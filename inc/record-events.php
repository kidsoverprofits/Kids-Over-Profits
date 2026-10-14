<?php
/**
 * One event for "a record changed" (docs/DATA-FLOWS.md section 4).
 *
 * Writers call kop_record_changed($kind, $ids) after they write: the shared
 * facility writer (kop_facility_save()), and for news, lawsuits and bills the
 * resync helpers in api/lib-record-resync.php. It
 *
 *   - bumps option kop_rev_<kind>, which kop_facility_pages_fingerprint(), the
 *     operator index and the app's ETags read (counts alone miss an approval,
 *     a reject or an edit);
 *   - fires action 'kop_record_changed' ($kind, $ids, $info);
 *   - at the end of the request, purges LiteSpeed for the /facility/ pages of
 *     the records changed (a news article or case: the facilities it is
 *     linked to).
 *
 * Kinds: facility, operator, news, lawsuit, bill, wiki. Outside WordPress
 * (the CLI tests) it does nothing.
 */

if (!function_exists('kop_record_changed')) {
    function kop_record_changed($kind, array $ids = array(), array $info = array()) {
        $kind = preg_replace('/[^a-z_]/', '', strtolower((string) $kind));
        if ($kind === '' || !function_exists('update_option')) return;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        update_option('kop_rev_' . $kind, (int) get_option('kop_rev_' . $kind, 0) + 1, false);
        $pending = &$GLOBALS['kop_record_changed_pending'];
        if (!is_array($pending)) {
            $pending = array();
            if (function_exists('add_action')) add_action('shutdown', 'kop_record_changed_purge', 5);
        }
        foreach ($ids as $id) $pending[$kind][$id] = true;
        if (function_exists('do_action')) do_action('kop_record_changed', $kind, $ids, $info);
    }
}

if (!function_exists('kop_record_revisions')) {
    /** "news:12|lawsuit:3" for the given kinds: what a fingerprint adds. */
    function kop_record_revisions(array $kinds) {
        $out = array();
        foreach ($kinds as $k) {
            $out[] = $k . ':' . (function_exists('get_option') ? (int) get_option('kop_rev_' . $k, 0) : 0);
        }
        return implode('|', $out);
    }
}

if (!function_exists('kop_record_changed_facility_ids')) {
    /** The facilities whose pages show these changed records. */
    function kop_record_changed_facility_ids(array $pending) {
        global $wpdb;
        $fids = array_keys($pending['facility'] ?? array());
        $joins = array('news' => array('news_facility_links', 'news_id'), 'lawsuit' => array('lawsuit_facility_links', 'lawsuit_id'));
        foreach ($joins as $kind => $j) {
            $ids = array_keys($pending[$kind] ?? array());
            if (!$ids || !isset($wpdb)) continue;
            $ids = array_slice(array_map('intval', $ids), 0, 500);
            $found = $wpdb->get_col("SELECT DISTINCT facility_id FROM {$j[0]} WHERE {$j[1]} IN (" . implode(',', $ids) . ')');
            foreach ((array) $found as $f) $fids[] = (int) $f;
        }
        return array_values(array_unique(array_filter(array_map('intval', $fids))));
    }
}

if (!function_exists('kop_record_changed_purge')) {
    /** shutdown: purge each changed record's facility page, at most 100 (else the whole cache). */
    function kop_record_changed_purge() {
        $pending = $GLOBALS['kop_record_changed_pending'] ?? array();
        $GLOBALS['kop_record_changed_pending'] = array();
        if (!$pending || !function_exists('do_action')) return;
        try {
            $fids = kop_record_changed_facility_ids($pending);
            if (count($fids) > 100) {
                do_action('litespeed_purge_all');
                return;
            }
            foreach ($fids as $fid) {
                $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '';
                if ($url !== '') do_action('litespeed_purge_url', $url);
            }
        } catch (Throwable $e) {
            error_log('kop_record_changed_purge: ' . $e->getMessage());
        }
    }
}
