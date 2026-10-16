<?php
/**
 * Facility records that are really a parent company.
 *
 * A few companies were filed as programs: "InnerChange" (Solacium, then
 * InnerChange, 2006-2018, then part of Embark) came in as an empty wiki entry
 * and became facility record 10455, with an office address and the head
 * office's staff, while the network map already drew it as a company. Such a
 * record leaves facilities_v2 (seeds/record-to-company.json, applied on deploy
 * before seeds/operator-records.json makes the company under the same
 * unique_name, so the wiki entry that names it still finds it):
 *   - link rows (company links, location memberships, news and lawsuit links,
 *     identity and address rows) are taken off;
 *   - queue rows that point at it (Woodbury facts and mentions, Fornits, Drive
 *     Docs, closure reports...) are unmatched (facility_id 0) so a reviewer can
 *     file them on the company;
 *   - /facility/<its slug or id>/ 301s to /operator/<the company>/.
 * The whole row and every row changed are kept in the option
 * kop_record_to_company_log; kop_rtc_undo() puts them back exactly, once the
 * company of that unique_name is gone (two records under one key never).
 *
 * Offline test: php -d extension=pdo_sqlite scripts/test-record-to-company.php
 * Guarded with function_exists so the test can load it next to WordPress stubs.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_rtc_link_tables')) {
    /** Tables whose rows only tie the record to something: taken off. {t, c, k: id or (unique) name} */
    function kop_rtc_link_tables($prefix) {
        return array(
            array('t' => $prefix . 'kop_operator_facilities', 'c' => 'facility_id'),
            array('t' => $prefix . 'kop_facility_locations', 'c' => 'facility_id'),
            array('t' => $prefix . 'kop_facility_identity', 'c' => 'facility_id'),
            array('t' => $prefix . 'kop_facility_addresses', 'c' => 'facility', 'k' => 'name'),   // keyed by the record's name
            array('t' => $prefix . 'kop_nonprofit_links', 'c' => 'facility_id'),
            array('t' => $prefix . 'kop_business_links', 'c' => 'facility_id'),
            array('t' => $prefix . 'kop_investor_links', 'c' => 'facility_id'),
            array('t' => 'news_facility_links', 'c' => 'facility_id'),
            array('t' => 'lawsuit_facility_links', 'c' => 'facility_id'),
        );
    }
}

if (!function_exists('kop_rtc_log')) {
    function kop_rtc_log() {
        $v = get_option('kop_record_to_company_log', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_rtc_convert')) {
    /**
     * Take facility record $fid (named $unique_name) out of facilities_v2 for
     * the company $company. Returns the log entry (also stored), or throws.
     */
    function kop_rtc_convert(PDO $pdo, $prefix, $fid, $unique_name, $company, $note = '') {
        $fid = (int) $fid;
        $log = kop_rtc_log();
        if (isset($log[$fid])) throw new RuntimeException('Record #' . $fid . ' was already converted.');
        $q = $pdo->prepare('SELECT * FROM facilities_v2 WHERE id = ?');
        $q->execute(array($fid));
        $raw = $q->fetch(PDO::FETCH_ASSOC);
        if (!$raw || $raw['unique_name'] !== (string) $unique_name) throw new RuntimeException('Record #' . $fid . ' is not ' . $unique_name . '.');

        $slugs = array((string) $fid);
        if (function_exists('kop_facility_pages_index')) {
            $index = kop_facility_pages_index();
            if (!empty($index['ids'][$fid]['slug'])) $slugs[] = strtolower((string) $index['ids'][$fid]['slug']);
        }
        if (function_exists('kop_facility_page_slug_candidate')) {
            $slugs[] = strtolower(kop_facility_page_slug_candidate((string) ($raw['name'] ?? $unique_name), strtoupper((string) ($raw['state'] ?? '')), (string) ($raw['country'] ?? '')));
        }

        $undo = array();
        $report = array();
        $links = array();
        foreach (kop_rtc_link_tables($prefix) as $l) {
            if (!in_array($l['c'], kop_fmerge_table_columns($pdo, $l['t']), true)) continue;
            $links[$l['t'] . '.' . $l['c']] = true;
            $key = ($l['k'] ?? 'id') === 'name' ? (string) $raw['unique_name'] : $fid;
            $sel = $pdo->prepare('SELECT * FROM `' . $l['t'] . '` WHERE `' . $l['c'] . '` = ?');
            $sel->execute(array($key));
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) continue;
            $pdo->prepare('DELETE FROM `' . $l['t'] . '` WHERE `' . $l['c'] . '` = ?')->execute(array($key));
            $undo[] = array('table' => $l['t'], 'col' => $l['c'], 'to' => $key, 'extra' => array(), 'moved' => array(), 'removed' => array(), 'deleted_rows' => $rows);
            $report[$l['t']] = count($rows);
        }
        // Everything else that holds the id by number waits unmatched; the
        // applied_fid columns stay as the record of what was done.
        $why = 'Its record became the parent company ' . $company . ': file these on the company.';
        foreach (kop_fmerge_ref_tables($prefix) as $ref) {
            if ($ref['k'] !== 'id' || $ref['c'] === 'applied_fid' || $ref['c'] === 'meta_value' || isset($links[$ref['t'] . '.' . $ref['c']])) continue;
            $cols = kop_fmerge_table_columns($pdo, $ref['t']);
            if (!$cols || !in_array($ref['c'], $cols, true)) continue;
            $extra = in_array('match_note', $cols, true) ? array('match_note' => $why) : array();
            // Only what still waits for review; a decided row keeps the record it was decided on.
            $where = array_filter(array((string) ($ref['where'] ?? ''), in_array('status', $cols, true) ? "status IN ('pending', 'gone')" : ''), 'strlen');
            list($moved, $removed) = kop_fmerge_move_rows($pdo, $ref['t'], $ref['c'], $fid, 0, $undo, $extra, implode(' AND ', $where));
            if ($moved || $removed) $report[$ref['t'] . '.' . $ref['c']] = $moved + $removed;
        }

        $pdo->prepare('DELETE FROM facilities_v2 WHERE id = ?')->execute(array($fid));

        $entry = array(
            'id'      => $fid,
            'name'    => (string) $raw['unique_name'],
            'company' => (string) $company,
            'slugs'   => array_values(array_unique(array_filter($slugs, 'strlen'))),
            'note'    => (string) $note,
            'at'      => gmdate('Y-m-d H:i:s'),
            'report'  => $report,
            'undo'    => array('raw' => $raw, 'steps' => $undo),
        );
        $log[$fid] = $entry;
        update_option('kop_record_to_company_log', $log, false);
        if (function_exists('kop_fmerge_flush')) kop_fmerge_flush();
        return $entry;
    }
}

if (!function_exists('kop_rtc_undo')) {
    /** Put converted record $fid back exactly. Refused while a company holds its unique_name. */
    function kop_rtc_undo(PDO $pdo, $prefix, $fid) {
        $fid = (int) $fid;
        $log = kop_rtc_log();
        if (empty($log[$fid]['undo']['raw'])) throw new RuntimeException('Record #' . $fid . ' has no conversion to undo.');
        $raw = $log[$fid]['undo']['raw'];
        $q = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}kop_operators` WHERE LOWER(unique_name) = LOWER(?)");
        $q->execute(array($raw['unique_name']));
        if ((int) $q->fetchColumn() > 0) {
            throw new RuntimeException('Delete or rename the company "' . $raw['unique_name'] . '" first: two records cannot share its key.');
        }
        $q = $pdo->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
        $q->execute(array($fid));
        if ((int) $q->fetchColumn() > 0) throw new RuntimeException('Record #' . $fid . ' is already back.');
        kop_fmerge_insert_row($pdo, 'facilities_v2', $raw);
        kop_fmerge_undo_rows($pdo, (array) $log[$fid]['undo']['steps']);
        unset($log[$fid]);
        update_option('kop_record_to_company_log', $log, false);
        if (function_exists('kop_fmerge_flush')) kop_fmerge_flush();
        return $fid;
    }
}

if (!function_exists('kop_rtc_redirect_url')) {
    /** Where /facility/<slug>/ of a converted record goes now, or ''. */
    function kop_rtc_redirect_url($slug) {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '') return '';
        foreach (kop_rtc_log() as $fid => $e) {
            $hit = in_array($slug, (array) ($e['slugs'] ?? array()), true)
                || (preg_match('/-(\d+)$/', $slug, $m) && (int) $m[1] === (int) $fid);
            if (!$hit) continue;
            $url = function_exists('kop_operator_page_url_for_name') ? kop_operator_page_url_for_name((string) $e['company']) : '';
            return $url !== '' ? $url : home_url('/' . (function_exists('kop_operator_pages_base') ? kop_operator_pages_base() : 'operator') . '/');
        }
        return '';
    }
}

if (!function_exists('kop_rtc_redirect')) {
    function kop_rtc_redirect() {
        if (!function_exists('kop_facility_pages_requested_slug')) return;
        $url = kop_rtc_redirect_url(kop_facility_pages_requested_slug());
        if ($url === '') return;
        wp_safe_redirect($url, 301);
        exit;
    }
    add_action('template_redirect', 'kop_rtc_redirect', -1);
}

if (!function_exists('kop_apply_record_to_company_seeds')) {
    /**
     * seeds/record-to-company.json, on deploy before the company seeds: each
     * entry {id, unique_name, company, note} converts once (the log keeps it),
     * only while the record is on file under that name.
     */
    function kop_apply_record_to_company_seeds() {
        $done = array();
        $path = trailingslashit(get_stylesheet_directory()) . 'seeds/record-to-company.json';
        if (!file_exists($path) || !function_exists('kop_fmerge_move_rows')) return $done;
        $entries = json_decode((string) file_get_contents($path), true);
        $pdo = kop_seed_pdo();
        if (!is_array($entries) || !$pdo) return $done;
        $prefix = kop_seed_v2_prefix($pdo);
        if ($prefix === null) return $done;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $log = kop_rtc_log();
        foreach ($entries as $e) {
            $fid = (int) ($e['id'] ?? 0);
            if (!$fid || isset($log[$fid]) || empty($e['company'])) continue;
            try {
                kop_rtc_convert($pdo, $prefix, $fid, (string) ($e['unique_name'] ?? ''), (string) $e['company'], (string) ($e['note'] ?? ''));
                $done[] = $fid;
            } catch (Throwable $ex) {
                error_log('kop_apply_record_to_company_seeds #' . $fid . ': ' . $ex->getMessage());
            }
        }
        return $done;
    }
}
