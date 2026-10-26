<?php
/**
 * Plan Board checks: items the site can see are finished, checked off on their own (owner, 2026-10-23).
 *
 * kop_pb_checks() maps a plan item id to a read-only check that returns [done, evidence] ("0 left in Wiki links",
 * "the crontab has the line"), or null when it cannot tell. The hourly kop_pb_checks_cron stores the results in the
 * option kop_pb_checks. On the board a passing item leaves Needs you and shows "Checked: done" with its evidence
 * under Waiting for Claude; "Not done" (the owner) sets an override that holds until the item is reworded. The
 * session's next pass sees it in the export (kind "auto") and archives the item like a Done.
 *
 * A check only reads. Queue items count as done when the queue is empty (caught up), from the Review inbox's own
 * stored counts. A check whose item has left the plan is never run, so stale entries here do no harm.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    if (!wp_next_scheduled('kop_pb_checks_cron')) {
        wp_schedule_event(time() + 300, 'hourly', 'kop_pb_checks_cron');
    }
});
add_action('kop_pb_checks_cron', 'kop_pb_checks_run');

/** The crontab as text, or null when this PHP cannot read it (web requests; shell_exec off). */
function kop_pb_crontab() {
    static $tab = false;
    if ($tab !== false) return $tab;
    $tab = null;
    if (PHP_SAPI === 'cli' && function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        $out = @shell_exec('crontab -l 2>/dev/null');
        if (is_string($out) && trim($out) !== '') $tab = $out;
    }
    return $tab;
}

/** The crontab's active line running $needle, or '' when none; null when the crontab cannot be read. */
function kop_pb_cron_line($needle) {
    $tab = kop_pb_crontab();
    if ($tab === null) return null;
    foreach (preg_split('/\R/', $tab) as $line) {
        if ($line !== '' && $line[0] !== '#' && strpos($line, $needle) !== false) return $line;
    }
    return '';
}

/** A Review inbox source's stored waiting count (null when not counted). */
function kop_pb_queue($key) {
    $c = function_exists('kop_rinbox_counts_stored') ? kop_rinbox_counts_stored() : get_option('kop_review_inbox_counts', array());
    return isset($c[$key]['n']) && $c[$key]['n'] !== null ? (int) $c[$key]['n'] : null;
}

/** Done when every one of these Review inbox queues is empty. $queues: source key => label. */
function kop_pb_queues_empty(array $queues) {
    $left = array();
    foreach ($queues as $key => $label) {
        $n = kop_pb_queue($key);
        if ($n === null) return null;
        if ($n > 0) $left[] = $n . ' left in ' . $label;
    }
    return $left ? array(false, implode(', ', $left)) : array(true, 'Nothing left in ' . implode(', ', array_values($queues)));
}

function kop_pb_post_status($id) {
    $p = get_post((int) $id);
    return $p ? $p->post_status : 'gone';
}

/** Rows of a records-database query (kop_seed_pdo()), or null when it fails. */
function kop_pb_records($sql, array $args = array()) {
    if (!function_exists('kop_seed_pdo')) return null;
    try {
        $st = kop_seed_pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
}

/** item id => callable returning [bool done, string evidence] or null (cannot tell). */
function kop_pb_checks() {
    $home = dirname(rtrim(ABSPATH, '/'));
    return array(
        '2.1:severe-findings-scan-cron' => function () use ($home) {
            $line = kop_pb_cron_line('scan-inspection-highlights.php');
            if ($line) return array(true, 'The crontab runs scan-inspection-highlights.php');
            $log = $home . '/logs/inspection-highlights-scan.log';
            if (file_exists($log) && filemtime($log) > time() - 3 * DAY_IN_SECONDS) return array(true, 'Its log was written ' . human_time_diff(filemtime($log)) . ' ago');
            return $line === null ? null : array(false, 'No scan line in the crontab');
        },
        '2.1:search-console' => function () use ($home) {
            $left = array_filter(array('kop-gsc.json', 'kop-gsc.err'), function ($f) use ($home) { return file_exists($home . '/' . $f); });
            return $left ? array(false, implode(' and ', $left) . ' still on the server') : array(true, 'The kop-gsc files are gone from the server');
        },
        '2.3:wiki-links' => function () { return kop_pb_queues_empty(array('wiki-links' => 'Wiki links')); },
        '2.3:data-audit' => function () { return kop_pb_queues_empty(array('data-audit' => 'Data audit')); },
        '2.3:record-cleanup' => function () { return kop_pb_queues_empty(array('record-cleanup' => 'Record cleanup')); },
        '2.3:rules-from-documents' => function () {
            return kop_pb_queues_empty(array('program-practices' => 'Rules from documents', 'company-milestones' => 'Company milestones',
                'federal-funding' => 'Federal funding', 'nonprofit-filings' => 'Nonprofit tax filings', 'business-filings' => 'Business filings'));
        },
        '2.3:map-years' => function () { return kop_pb_queues_empty(array('map-years' => 'Map Years', 'map-renames' => 'Map Renames')); },
        '2.3:woodbury-reports' => function () { return kop_pb_queues_empty(array('woodbury-reports' => 'Woodbury Reports')); },
        '2.3:program-homes' => function () { return kop_pb_queues_empty(array('program-homes' => 'Program Homes')); },
        '2.3:inspection-links' => function () { return kop_pb_queues_empty(array('inspection-links' => 'Inspection Links')); },
        '2.3:serious-findings' => function () { return kop_pb_queues_empty(array('inspection-highlights' => 'Serious findings')); },
        '2.3:records-made-from-descriptions' => function () {
            $names = array(100554 => 'youth treatment facility', 100610 => "girls' boot camp at Plankinton", 100383 => 'private home in South Hobart, Tasmania',
                100401 => 'group home in Centre Wellington', 100508 => 'People');
            $rows = kop_pb_records('SELECT id, name FROM facilities_v2 WHERE id IN (' . implode(',', array_keys($names)) . ')');
            if ($rows === null) return null;
            $left = array_filter($rows, function ($r) use ($names) { return trim((string) $r['name']) === $names[(int) $r['id']]; });
            return $left ? array(false, count($left) . ' of 5 still carry the description as a name') : array(true, 'All five are renamed or removed');
        },
        '2.4:linden-oaks-il-100402-reject-data-submissions-56-57-and-58' => function () {
            $rows = kop_pb_records('SELECT id, status FROM suggested_edits WHERE id IN (56, 57, 58)');
            if ($rows === null) return null;
            $pending = array_filter($rows, function ($r) { return $r['status'] === 'pending'; });
            return $pending ? array(false, count($pending) . ' of the 3 still pending') : array(true, '#56, #57 and #58 are no longer pending');
        },
        '2.5:the-brochure-and-the-record' => function () {
            $p = get_page_by_path('the-brochure-and-the-record');
            if (!$p) return null;
            return $p->post_status === 'publish' ? array(true, 'The page is published') : array(false, 'The page is ' . $p->post_status);
        },
        '4.3:young-adult-programs-what-is-left-after-the-document-folders' => function () {
            global $wpdb;
            $n = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kop_folder_links WHERE (folder_a = 7789 AND folder_b = 4163) OR (folder_a = 4163 AND folder_b = 7789)");
            if ($n === null) return null;
            return (int) $n ? array(true, 'Folder 7789 is linked to 4163') : array(false, 'Folder 7789 is not linked yet');
        },
        '4.7:meta-descriptions-yoast-sees' => function () {
            $a = kop_pb_post_status(13312);
            $walrus = array_filter(array(470, 786), function ($id) { return kop_pb_post_status($id) === 'publish'; });
            $left = array();
            if ($a === 'publish') $left[] = 'post 13312 is still published';
            if (count($walrus) > 1) $left[] = 'both Walrus posts are published';
            return $left ? array(false, ucfirst(implode('; ', $left))) : array(true, 'Post 13312 is unpublished and one Walrus post is left');
        },
    );
}

/** Runs every check whose item is on the plan and stores the results: item id => {done, evidence, at}. */
function kop_pb_checks_run() {
    $items = kop_pb_items(kop_pb_plan());
    $out = array();
    foreach (kop_pb_checks() as $id => $fn) {
        if (!isset($items[$id])) continue;
        try {
            $r = $fn();
        } catch (Throwable $e) {
            $r = null;
        }
        if (!is_array($r)) continue;
        $out[$id] = array('done' => (bool) $r[0], 'evidence' => (string) $r[1], 'at' => time());
    }
    update_option('kop_pb_checks', $out, false);
    return $out;
}

/** "Not done" from the owner: item id => the item hash it was said for. */
function kop_pb_check_overrides() {
    $o = get_option('kop_pb_check_overrides', array());
    return is_array($o) ? $o : array();
}

function kop_pb_check_override($item_id, $hash) {
    $o = kop_pb_check_overrides();
    $o[$item_id] = (string) $hash;
    update_option('kop_pb_check_overrides', $o, false);
}

/** The items checked off: item id => {evidence, at}, minus those the owner said are not done (for this wording). */
function kop_pb_auto_done(array $items, $checks = null, $overrides = null) {
    $checks = $checks ?? get_option('kop_pb_checks', array());
    $overrides = $overrides ?? kop_pb_check_overrides();
    $out = array();
    foreach ((array) $checks as $id => $c) {
        if (empty($c['done']) || !isset($items[$id])) continue;
        if (isset($overrides[$id]) && $overrides[$id] === (string) ($items[$id]['hash'] ?? '')) continue;
        $out[$id] = $c;
    }
    return $out;
}
