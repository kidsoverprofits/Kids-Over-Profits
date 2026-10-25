<?php
/**
 * The Review inbox's areas and its "Just in" tab.
 *
 * Areas: every queue sits in one of four tabs on the page (js/review-inbox.js
 * renderTabs()): what people sent in, the daily news scans, what imports and
 * the state inspection scans found, and checks and cleanup. The News queue
 * shows in two: "Sent in by people" opens it on origin 'people', "Daily news
 * scans" on origin 'scans' (kop_rinbox_native_origins()), with those counts
 * (kop_rinbox_native_split_counts()). kop_rinbox_area() reads it from the queue's 'group' (or its own
 * 'area' key), so a new queue lands in a tab without a list to update.
 *
 * Just in (REST review-inbox/recent?sources=a,b&days=2): what arrived in each
 * queue in the last few days, newest first. Each item carries 'people': true
 * for what a person sent in (the "Sent in by people" area; for News, Lawsuits,
 * Legislation and Wiki only the rows whose origin is "People and admins",
 * kop_rinbox_native_origins()) and 'scan': true for what the daily news scans
 * found. The page shows people's items as cards and folds the rest into a
 * count per queue: the news scans in one fold, everything else in another. A queue with a 'recent' callable answers
 * fn(int $since_ts, int $limit): items itself (the five native types, by
 * created_at); a queue nobody sends to may give 'recent_count' fn(int $since_ts): int
 * instead (Drive Docs, Fornits: their cards are slow to draw); any other is read from its waiting view (kop_rinbox_list_unheld(),
 * so snoozed, on-file and conflict items stay out) a page at a time, keeping
 * items whose 'created' is on or after the cutoff, stopping once a page sorted
 * newest first runs past it, at most KOP_RINBOX_RECENT_SCAN items. A queue
 * whose items carry no date never shows here.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_RINBOX_RECENT_SCAN = 300;

/** The tabs the queues sit in: [area => label], in page order. */
function kop_rinbox_areas() {
    return array(
        'people' => 'Sent in by people',
        'scans'  => 'Daily news scans',
        'found'  => 'Found by imports and inspections',
        'checks' => 'Checks and cleanup',
    );
}

/** Which area a queue sits in: its own 'area', else by its group. */
function kop_rinbox_area(array $src) {
    if (!empty($src['area']) && isset(kop_rinbox_areas()[$src['area']])) return $src['area'];
    $group = (string) ($src['group'] ?? '');
    if (in_array($group, array('Submissions', 'Sent in by readers', 'Essential reporting'), true)) return 'people';
    // The daily news scans keep a tab of their own (owner, 2026-10-25).
    if ($group === 'Found by the news scans') return 'scans';
    if (in_array($group, array('Found in state inspection reports', 'Imports to review', 'Documents'), true)) return 'found';
    return 'checks';
}

/** A 'created' value as a timestamp, or 0 when it is not a date. */
function kop_rinbox_created_ts($created) {
    $created = trim((string) $created);
    if ($created === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $created)) return 0;
    $ts = strtotime($created);
    return $ts ? (int) $ts : 0;
}

/** What arrived in $source on or after $since_ts: finished items, newest first, each with 'people'. */
function kop_rinbox_recent_items($source, $since_ts) {
    $src = kop_rinbox_source($source);
    $area = kop_rinbox_area($src);
    $people = $area === 'people';
    if (!empty($src['recent'])) {
        $items = (array) call_user_func($src['recent'], (int) $since_ts, KOP_RINBOX_RECENT_SCAN);
    } else {
        $items = array();
        $views = array_keys($src['views']);
        // A first page of 20: a queue whose items carry no date (or none recent, newest first) stops there.
        $q = array('view' => $views[0], 'search' => '', 'offset' => 0, 'limit' => 20, 'origin' => '', 'filters' => array());
        while ($q['offset'] < KOP_RINBOX_RECENT_SCAN) {
            $res = kop_rinbox_list_unheld($src, $source, $q);
            $page = (array) ($res['items'] ?? array());
            $last = null;
            $sorted = true;
            $dated = false;
            foreach ($page as $it) {
                $ts = kop_rinbox_created_ts($it['created'] ?? '');
                if ($ts > 0) $dated = true;
                if ($last !== null && $ts > $last) $sorted = false;
                $last = $ts;
                if ($ts >= $since_ts && $ts > 0) $items[] = $it;
            }
            // Newest first and already past the cutoff: nothing newer further down.
            if (!$dated || count($page) < $q['limit'] || ($sorted && $last !== null && $last < $since_ts)) break;
            $next = (int) ($res['next_offset'] ?? 0);
            if ($next <= $q['offset']) break;
            $q['offset'] = $next;
            $q['limit'] = 100;
        }
        foreach ($items as &$it) {
            $it['people'] = $it['people'] ?? $people;
            $it['scan'] = $it['scan'] ?? ($area === 'scans');
        }
        unset($it);
    }
    usort($items, function ($a, $b) {
        return kop_rinbox_created_ts($b['created'] ?? '') <=> kop_rinbox_created_ts($a['created'] ?? '');
    });
    $items = kop_rinbox_finish_items($source, $items);
    foreach ($items as &$it) {
        $it['source'] = $source;
        $it['source_label'] = $src['label'];
        $it['native'] = !empty($src['native']);
        $it['people'] = !empty($it['people']);
        $it['scan'] = !empty($it['scan']) && !$it['people'];
    }
    unset($it);
    return $items;
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/review-inbox/recent', array(
        'methods' => 'GET', 'callback' => 'kop_rinbox_rest_recent',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ));
});

/**
 * ?sources=a,b&days=2: [source => {people: [items], scans: n, others: n}]. The page asks
 * a few queues a request so the quick ones show first; a queue that fails says
 * so in 'error' and the rest still answer.
 */
function kop_rinbox_rest_recent(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $days = max(1, min(14, (int) ($req->get_param('days') ?: 2)));
        $since = time() - $days * DAY_IN_SECONDS;
        $keys = array_slice(array_filter(array_map('sanitize_key', explode(',', (string) $req->get_param('sources'))), 'strlen'), 0, 20);
        $out = array();
        foreach ($keys as $key) {
            try {
                $src = kop_rinbox_source($key);
                if (!empty($src['recent_count']) && kop_rinbox_area($src) !== 'people') {
                    $n = (int) call_user_func($src['recent_count'], $since);
                    $news = kop_rinbox_area($src) === 'scans';
                    $out[$key] = array('people' => array(), 'scans' => $news ? $n : 0, 'others' => $news ? 0 : $n);
                    continue;
                }
                $items = kop_rinbox_recent_items($key, $since);
                $people = array_values(array_filter($items, function ($it) { return $it['people']; }));
                $scans = count(array_filter($items, function ($it) { return $it['scan']; }));
                $out[$key] = array('people' => $people, 'scans' => $scans, 'others' => count($items) - count($people) - $scans);
            } catch (Throwable $e) {
                $out[$key] = array('people' => array(), 'scans' => 0, 'others' => 0, 'error' => $e->getMessage());
            }
        }
        return array('recent' => $out, 'days' => $days);
    });
}
