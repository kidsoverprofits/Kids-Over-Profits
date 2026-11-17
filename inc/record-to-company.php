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
 * A record filed beside a company that already exists ("Aspen Education
 * Group #2" next to the company Aspen Education Group, 2026-11-17) is folded
 * into it ('fold': true on the seed entry, kop_rtc_fold()): its staff join
 * the company's keyExecutives (one entry per person, roles joined), its notes
 * and incident lines join the company's notes, its document folder fills an
 * empty one. Company pages, careers and the map then read them there. Undo
 * puts the company back as it was (or, edited since, takes off only what the
 * fold added).
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
    function kop_rtc_convert(PDO $pdo, $prefix, $fid, $unique_name, $company, $note = '', array $opts = array()) {
        $fid = (int) $fid;
        $log = kop_rtc_log();
        if (isset($log[$fid])) throw new RuntimeException('Record #' . $fid . ' was already converted.');
        $q = $pdo->prepare('SELECT * FROM facilities_v2 WHERE id = ?');
        $q->execute(array($fid));
        $raw = $q->fetch(PDO::FETCH_ASSOC);
        if (!$raw || $raw['unique_name'] !== (string) $unique_name) throw new RuntimeException('Record #' . $fid . ' is not ' . $unique_name . '.');
        // Folding needs the company on file before anything is changed.
        $fold = null;
        if (!empty($opts['fold'])) {
            $fold = kop_rtc_fold($pdo, $prefix, $raw, (string) $company);
        }

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
        if ($fold) {
            $fold = kop_rtc_fold_write($pdo, $prefix, $fold);
            $entry['report']['folded'] = $fold['added'];
            $entry['undo']['fold'] = $fold;
        }
        $log[$fid] = $entry;
        update_option('kop_record_to_company_log', $log, false);
        if (function_exists('kop_fmerge_flush')) kop_fmerge_flush();
        if ($fold && function_exists('kop_record_changed')) kop_record_changed('operator', array((int) $fold['id']));
        return $entry;
    }
}

if (!function_exists('kop_rtc_fold')) {
    /**
     * Plan the fold of facility row $raw into the existing company $company:
     * {id, before, after (json strings), folder_before, folder_after, added}.
     * Nothing is written here (kop_rtc_fold_write()). Throws when no company
     * has that name.
     */
    function kop_rtc_fold(PDO $pdo, $prefix, array $raw, $company) {
        $q = $pdo->prepare("SELECT id, json_data, document_folder_id FROM `{$prefix}kop_operators` WHERE name = ? ORDER BY id LIMIT 1");
        $q->execute(array($company));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('No company named ' . $company . ' to fold the record into.');
        $json = json_decode((string) $row['json_data'], true);
        if (!is_array($json)) $json = array();
        $op = isset($json['operator']) && is_array($json['operator']) ? $json['operator'] : array();
        $doc = json_decode((string) $raw['json_data'], true);
        if (!is_array($doc)) $doc = array();

        // Staff: administrators first, then the rest; one entry per person (personId, else the name).
        $key_of = static function ($p) {
            $pid = (int) ($p['personId'] ?? 0);
            return $pid > 0 ? 'id:' . $pid : 'name:' . strtolower(trim(preg_replace('/\s+/', ' ', (string) ($p['name'] ?? ''))));
        };
        $staff = is_array($op['keyStaff'] ?? null) ? $op['keyStaff'] : array();
        $execs = array_values(array_filter((array) ($staff['keyExecutives'] ?? array()), 'is_array'));
        $have = array();
        foreach ($execs as $p) $have[$key_of($p)] = true;
        $new = array();
        foreach (array('administrator', 'notableStaff') as $list) {
            foreach ((array) ($doc['staff'][$list] ?? array()) as $p) {
                if (!is_array($p) || trim((string) ($p['name'] ?? '')) === '') continue;
                $k = $key_of($p);
                if (isset($have[$k])) continue;
                if (isset($new[$k])) {
                    // The same person twice (Jim Dredge / James M. Dredge): one entry, both roles.
                    $role = trim((string) ($p['role'] ?? ''));
                    if ($role !== '' && stripos($new[$k]['role'] ?? '', $role) === false) {
                        $new[$k]['role'] = trim(($new[$k]['role'] ?? '') . '; ' . $role, '; ');
                    }
                    continue;
                }
                $new[$k] = $p;
            }
        }
        $people = array_values($new);
        $staff['keyExecutives'] = array_merge($execs, $people);
        if (!isset($staff['ceo'])) $staff['ceo'] = '';
        if (!isset($staff['founders'])) $staff['founders'] = array();

        // Notes and incident lines, each once.
        $notes = array_values(array_map('strval', (array) ($op['notes'] ?? array())));
        $add_notes = array();
        $from = array_merge((array) ($doc['notes'] ?? array()), (array) ($doc['criticalIncidents']['customIncidents'] ?? array()));
        foreach ($from as $n) {
            $n = trim((string) $n);
            if ($n !== '' && !in_array($n, $notes, true) && !in_array($n, $add_notes, true)) $add_notes[] = $n;
        }
        $op['keyStaff'] = $staff;
        $op['notes'] = array_merge($notes, $add_notes);
        $json['operator'] = $op;

        $folder_before = (int) ($row['document_folder_id'] ?? 0);
        $folder_rec = (int) ($doc['documentFolderId'] ?? 0);
        return array(
            'id'            => (int) $row['id'],
            'before'        => (string) $row['json_data'],
            'after'         => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'folder_before' => $folder_before,
            'folder_after'  => $folder_before ?: $folder_rec,
            'people'        => $people,
            'notes'         => $add_notes,
            'added'         => array('people' => count($people), 'notes' => count($add_notes), 'folder' => !$folder_before && $folder_rec ? $folder_rec : 0),
        );
    }
}

if (!function_exists('kop_rtc_fold_write')) {
    /** Write a planned fold; returns it with the stored strings. */
    function kop_rtc_fold_write(PDO $pdo, $prefix, array $fold) {
        $pdo->prepare("UPDATE `{$prefix}kop_operators` SET json_data = ? WHERE id = ?")
            ->execute(array($fold['after'], (int) $fold['id']));
        if (!empty($fold['added']['folder'])) {
            $pdo->prepare("UPDATE `{$prefix}kop_operators` SET document_folder_id = ? WHERE id = ?")
                ->execute(array((int) $fold['added']['folder'], (int) $fold['id']));
        }
        return $fold;
    }
}

if (!function_exists('kop_rtc_unfold')) {
    /**
     * Take a fold back off its company: the company as it was when nothing
     * has changed it since, else only the people and notes the fold added.
     */
    function kop_rtc_unfold(PDO $pdo, $prefix, array $fold) {
        $q = $pdo->prepare("SELECT json_data, document_folder_id FROM `{$prefix}kop_operators` WHERE id = ?");
        $q->execute(array((int) $fold['id']));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        if ((string) $row['json_data'] === (string) $fold['after']) {
            $json_out = (string) $fold['before'];
        } else {
            $json = json_decode((string) $row['json_data'], true);
            if (!is_array($json) || !isset($json['operator']) || !is_array($json['operator'])) return;
            $op = $json['operator'];
            $added = array_map('serialize', (array) ($fold['people'] ?? array()));
            $op['keyStaff']['keyExecutives'] = array_values(array_filter((array) ($op['keyStaff']['keyExecutives'] ?? array()), static function ($p) use ($added) {
                return !in_array(serialize($p), $added, true);
            }));
            $op['notes'] = array_values(array_diff(array_map('strval', (array) ($op['notes'] ?? array())), (array) ($fold['notes'] ?? array())));
            $json['operator'] = $op;
            $json_out = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $pdo->prepare("UPDATE `{$prefix}kop_operators` SET json_data = ? WHERE id = ?")
            ->execute(array($json_out, (int) $fold['id']));
        // The folder the fold filled is emptied again, unless someone has set another since.
        if (!empty($fold['added']['folder']) && (int) $row['document_folder_id'] === (int) $fold['added']['folder']) {
            $pdo->prepare("UPDATE `{$prefix}kop_operators` SET document_folder_id = NULL WHERE id = ?")->execute(array((int) $fold['id']));
        }
        if (function_exists('kop_record_changed')) kop_record_changed('operator', array((int) $fold['id']));
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
        if (!empty($log[$fid]['undo']['fold'])) kop_rtc_unfold($pdo, $prefix, (array) $log[$fid]['undo']['fold']);
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
     * entry {id, unique_name, company, note, fold?} converts once (the log keeps it),
     * only while the record is on file under that name. 'fold': true carries
     * the record's staff, notes and folder into the existing company (kop_rtc_fold()).
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
                kop_rtc_convert($pdo, $prefix, $fid, (string) ($e['unique_name'] ?? ''), (string) $e['company'], (string) ($e['note'] ?? ''), array('fold' => !empty($e['fold'])));
                $done[] = $fid;
            } catch (Throwable $ex) {
                error_log('kop_apply_record_to_company_seeds #' . $fid . ': ' . $ex->getMessage());
            }
        }
        return $done;
    }
}
