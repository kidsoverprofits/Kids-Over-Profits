<?php
/**
 * Facility records that belong in a related category (inc/related-facilities.php),
 * such as an Adult & Teen Challenge center for men that was filed as a troubled
 * teen program. The record leaves facilities_v2 for related_facilities
 * (seeds/related-moves.json, applied on deploy):
 *   - its document is copied to a new related record (a new id above
 *     KOP_RELATED_FIRST_ID), keeping the document folder its page showed;
 *   - the links a related record keeps (company, news, lawsuit, nonprofit
 *     filing) are pointed at the new id, so its page still shows them;
 *   - the links only a TTI facility has (location pages, identity, addresses,
 *     business and investor links) are taken off;
 *   - queue rows that point at it (Woodbury facts and mentions, Fornits,
 *     Drive Docs...) and still wait are unmatched (facility_id 0) with a note,
 *     so a reviewer files them; decided ones keep the old id as the record of
 *     what was done;
 *   - /facility/<its slug or id>/ 301s to its /related/<category>/<slug>/ page.
 * The whole row and every row changed are kept in the option
 * kop_related_move_log; kop_rmove_undo() puts them back exactly and removes
 * the related record.
 *
 * Offline test: php -d extension=pdo_sqlite -d extension=mbstring scripts/test-related-move.php
 * Guarded with function_exists so the test can load it next to WordPress stubs.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_rmove_kept_tables')) {
    /** Link tables that hold related ids too: their rows move to the new record. */
    function kop_rmove_kept_tables($prefix) {
        return array(
            array('t' => $prefix . 'kop_operator_facilities', 'c' => 'facility_id'),
            array('t' => $prefix . 'kop_nonprofit_links', 'c' => 'facility_id'),
            array('t' => 'news_facility_links', 'c' => 'facility_id'),
            array('t' => 'lawsuit_facility_links', 'c' => 'facility_id'),
        );
    }
}

if (!function_exists('kop_rmove_dropped_tables')) {
    /** Tables only a TTI facility has rows in: taken off. {t, c, k: id or (unique) name} */
    function kop_rmove_dropped_tables($prefix) {
        return array(
            array('t' => $prefix . 'kop_facility_locations', 'c' => 'facility_id'),
            array('t' => $prefix . 'kop_facility_identity', 'c' => 'facility_id'),
            array('t' => $prefix . 'kop_facility_addresses', 'c' => 'facility', 'k' => 'name'),   // keyed by the record's name
            array('t' => $prefix . 'kop_business_links', 'c' => 'facility_id'),
            array('t' => $prefix . 'kop_investor_links', 'c' => 'facility_id'),
        );
    }
}

if (!function_exists('kop_rmove_log')) {
    function kop_rmove_log() {
        $v = get_option('kop_related_move_log', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_rmove_convert')) {
    /**
     * Move facility record $fid (named $unique_name) into related category
     * $category. Returns the log entry (also stored), or throws.
     */
    function kop_rmove_convert(PDO $pdo, $prefix, $fid, $unique_name, $category, $note = '') {
        $fid = (int) $fid;
        $cat = function_exists('kop_related_category') ? kop_related_category($category) : null;
        if (!$cat) throw new RuntimeException('Unknown category ' . $category . '.');
        $log = kop_rmove_log();
        if (isset($log[$fid])) throw new RuntimeException('Record #' . $fid . ' was already moved.');
        $q = $pdo->prepare('SELECT * FROM facilities_v2 WHERE id = ?');
        $q->execute(array($fid));
        $raw = $q->fetch(PDO::FETCH_ASSOC);
        if (!$raw || $raw['unique_name'] !== (string) $unique_name) throw new RuntimeException('Record #' . $fid . ' is not ' . $unique_name . '.');
        $doc = json_decode((string) $raw['json_data'], true);
        if (!is_array($doc)) throw new RuntimeException('Record #' . $fid . ' does not read.');

        $slugs = array((string) $fid);
        if (function_exists('kop_facility_pages_index')) {
            $index = kop_facility_pages_index();
            if (!empty($index['ids'][$fid]['slug'])) $slugs[] = strtolower((string) $index['ids'][$fid]['slug']);
        }
        if (function_exists('kop_facility_page_slug_candidate')) {
            $slugs[] = strtolower(kop_facility_page_slug_candidate((string) ($raw['name'] ?? $unique_name), strtoupper((string) ($raw['state'] ?? '')), (string) ($raw['country'] ?? '')));
        }

        // The related record: the same document, with the folder its page showed.
        $copy = function_exists('kop_facility_to_legacy') ? kop_facility_to_legacy($doc) : $doc;
        unset($copy['facility_id']);
        $folder = function_exists('kop_fmerge_page_folder') ? (int) kop_fmerge_page_folder($fid, $doc, (string) $raw['unique_name']) : 0;
        if ($folder > 0 && empty($copy['documentFolderId'])) $copy['documentFolderId'] = $folder;
        $opts = array('pdo' => $pdo, 'prefix' => $prefix);
        $max = (int) $pdo->query('SELECT MAX(id) FROM related_facilities')->fetchColumn();
        $rid = max($max, KOP_RELATED_FIRST_ID + 1000) + 1;
        kop_related_insert_at($category, $copy, $rid, 'approved', $opts);

        $undo = array();
        $report = array();
        $handled = array();
        foreach (kop_rmove_kept_tables($prefix) as $l) {
            $handled[$l['t'] . '.' . $l['c']] = true;
            list($moved, $removed) = kop_fmerge_move_rows($pdo, $l['t'], $l['c'], $fid, $rid, $undo);
            if ($moved || $removed) $report[$l['t']] = $moved + $removed;
        }
        foreach (kop_rmove_dropped_tables($prefix) as $l) {
            if (!in_array($l['c'], kop_fmerge_table_columns($pdo, $l['t']), true)) continue;
            $handled[$l['t'] . '.' . $l['c']] = true;
            $key = ($l['k'] ?? 'id') === 'name' ? (string) $raw['unique_name'] : $fid;
            $sel = $pdo->prepare('SELECT * FROM `' . $l['t'] . '` WHERE `' . $l['c'] . '` = ?');
            $sel->execute(array($key));
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) continue;
            $pdo->prepare('DELETE FROM `' . $l['t'] . '` WHERE `' . $l['c'] . '` = ?')->execute(array($key));
            $undo[] = array('table' => $l['t'], 'col' => $l['c'], 'to' => $key, 'extra' => array(), 'moved' => array(), 'removed' => array(), 'deleted_rows' => $rows);
            $report[$l['t']] = count($rows);
        }
        // Everything else that holds the id by number and still waits is unmatched.
        $why = 'Its record moved to ' . $cat['label'] . ' (record #' . $rid . '): file these there or on another record.';
        foreach (kop_fmerge_ref_tables($prefix) as $ref) {
            if ($ref['k'] !== 'id' || $ref['c'] === 'applied_fid' || $ref['c'] === 'meta_value' || isset($handled[$ref['t'] . '.' . $ref['c']])) continue;
            $cols = kop_fmerge_table_columns($pdo, $ref['t']);
            if (!$cols || !in_array($ref['c'], $cols, true)) continue;
            $extra = in_array('match_note', $cols, true) ? array('match_note' => $why) : array();
            $where = array_filter(array((string) ($ref['where'] ?? ''), in_array('status', $cols, true) ? "status IN ('pending', 'gone')" : ''), 'strlen');
            list($moved, $removed) = kop_fmerge_move_rows($pdo, $ref['t'], $ref['c'], $fid, 0, $undo, $extra, implode(' AND ', $where));
            if ($moved || $removed) $report[$ref['t'] . '.' . $ref['c']] = $moved + $removed;
        }

        $pdo->prepare('DELETE FROM facilities_v2 WHERE id = ?')->execute(array($fid));

        $entry = array(
            'id'         => $fid,
            'name'       => (string) $raw['unique_name'],
            'related_id' => $rid,
            'category'   => (string) $category,
            'slugs'      => array_values(array_unique(array_filter($slugs, 'strlen'))),
            'note'       => (string) $note,
            'at'         => gmdate('Y-m-d H:i:s'),
            'report'     => $report,
            'undo'       => array('raw' => $raw, 'steps' => $undo),
        );
        $log[$fid] = $entry;
        update_option('kop_related_move_log', $log, false);
        if (function_exists('kop_fmerge_flush')) kop_fmerge_flush();
        if (function_exists('kop_related_flush')) kop_related_flush();
        return $entry;
    }
}

if (!function_exists('kop_rmove_undo')) {
    /** Put moved record $fid back exactly and remove its related record. */
    function kop_rmove_undo(PDO $pdo, $prefix, $fid) {
        $fid = (int) $fid;
        $log = kop_rmove_log();
        if (empty($log[$fid]['undo']['raw'])) throw new RuntimeException('Record #' . $fid . ' has no move to undo.');
        $q = $pdo->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
        $q->execute(array($fid));
        if ((int) $q->fetchColumn() > 0) throw new RuntimeException('Record #' . $fid . ' is already back.');
        kop_fmerge_insert_row($pdo, 'facilities_v2', $log[$fid]['undo']['raw']);
        kop_fmerge_undo_rows($pdo, (array) $log[$fid]['undo']['steps']);
        // Only the record row: links filed on it since stay with its id, for whoever looks.
        $pdo->prepare('DELETE FROM related_facilities WHERE id = ?')->execute(array((int) $log[$fid]['related_id']));
        unset($log[$fid]);
        update_option('kop_related_move_log', $log, false);
        if (function_exists('kop_fmerge_flush')) kop_fmerge_flush();
        if (function_exists('kop_related_flush')) kop_related_flush();
        return $fid;
    }
}

if (!function_exists('kop_rmove_redirect_url')) {
    /** Where /facility/<slug>/ of a moved record goes now, or ''. */
    function kop_rmove_redirect_url($slug) {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '') return '';
        foreach (kop_rmove_log() as $fid => $e) {
            $hit = in_array($slug, (array) ($e['slugs'] ?? array()), true)
                || (preg_match('/-(\d+)$/', $slug, $m) && (int) $m[1] === (int) $fid);
            if (!$hit) continue;
            $url = function_exists('kop_related_url') ? kop_related_url((int) $e['related_id']) : '';
            return $url !== '' ? $url : (function_exists('kop_related_category_url') ? kop_related_category_url((string) $e['category']) : '');
        }
        return '';
    }
}

if (!function_exists('kop_rmove_redirect')) {
    function kop_rmove_redirect() {
        if (!function_exists('kop_facility_pages_requested_slug')) return;
        $url = kop_rmove_redirect_url(kop_facility_pages_requested_slug());
        if ($url === '') return;
        wp_safe_redirect($url, 301);
        exit;
    }
    add_action('template_redirect', 'kop_rmove_redirect', -1);
}

if (!function_exists('kop_apply_related_move_seeds')) {
    /**
     * seeds/related-moves.json, on deploy: each entry {id, unique_name,
     * category, note} moves once (the log keeps it), only while the record is
     * on file under that name.
     */
    function kop_apply_related_move_seeds() {
        $done = array();
        $path = trailingslashit(get_stylesheet_directory()) . 'seeds/related-moves.json';
        if (!file_exists($path) || !function_exists('kop_fmerge_move_rows') || !function_exists('kop_related_ready') || !kop_related_ready()) return $done;
        $entries = json_decode((string) file_get_contents($path), true);
        $pdo = kop_seed_pdo();
        if (!is_array($entries) || !$pdo) return $done;
        $prefix = kop_seed_v2_prefix($pdo);
        if ($prefix === null) return $done;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $log = kop_rmove_log();
        foreach ($entries as $e) {
            $fid = (int) ($e['id'] ?? 0);
            if (!$fid || isset($log[$fid]) || empty($e['category'])) continue;
            try {
                kop_rmove_convert($pdo, $prefix, $fid, (string) ($e['unique_name'] ?? ''), (string) $e['category'], (string) ($e['note'] ?? ''));
                $done[] = $fid;
            } catch (Throwable $ex) {
                error_log('kop_apply_related_move_seeds #' . $fid . ': ' . $ex->getMessage());
            }
        }
        if ($done && function_exists('kop_facility_pages_flush_index')) kop_facility_pages_flush_index();
        foreach ($done as $fid) do_action('kop_facility_status_changed', $fid);
        return $done;
    }
}
