<?php
/**
 * After a news article or lawsuit changes, bring everything that is derived
 * from it up to date (docs/DATA-FLOWS.md, PLAN 3.17).
 *
 * The full save paths (api/save-news-submission.php, api/save-lawsuit.php)
 * always ran these steps; approve, publish, status changes, Undo, moves and
 * the Data Manager often did not, so facility pages, case cards and story
 * groups drifted from the row. Every path that writes these tables calls one
 * of these after its write:
 *
 *   kop_resync_news($pdo, $ids)       facility links from facilities_mentioned,
 *                                     story group, story arc, journalists,
 *                                     the article's case links
 *   kop_resync_lawsuit($pdo, $ids)    facility links from facilities_mentioned,
 *                                     the case's news links; with
 *                                     ['documents' => true] also the FileBird
 *                                     folder and document filing (going live)
 *   kop_resync_after_status($pdo, $type, $ids, $status)   either of the two
 *                                     for a review type set to a status
 *   kop_records_deleted($pdo, $type, $ids)   link rows of deleted rows
 *
 * Each ends with kop_record_changed() (inc/record-events.php): the kind's
 * revision moves the page caches and the app's ETags, and the facility pages
 * showing the record are purged from LiteSpeed.
 * None of them ever throws: a failed step is logged and the write stands.
 */

require_once __DIR__ . '/news-mentions.php';
require_once __DIR__ . '/news-story-groups.php';
require_once __DIR__ . '/news-story-arcs.php';
require_once __DIR__ . '/lawsuit-facility-links.php';
require_once __DIR__ . '/lawsuit-news-links.php';
require_once __DIR__ . '/lib-journalists.php';
require_once dirname(__DIR__) . '/inc/record-events.php';   // kop_record_changed()

if (!function_exists('kop_resync_ids')) {
    /** @internal ints > 0, once each. */
    function kop_resync_ids($ids) {
        return array_values(array_unique(array_filter(array_map('intval', (array) $ids), function ($i) {
            return $i > 0;
        })));
    }
}

if (!function_exists('kop_resync_keep_underived')) {
    /**
     * Once (option kop_resync_keep_underived = 1): a facility link typed
     * 'mentioned' that the record's own facilities_mentioned no longer gives
     * is retyped 'related', so it is kept as a link made by hand. Without
     * this, the first resync of each record would drop it: on 2026-10-13 that
     * was 61 news links (52 on live articles; Google Docs, SCIAD NET and
     * discovery imports) and 8 case links (Wood v. WWASP among them). Pages
     * show the same links before and after. Runs before the first resync.
     *
     * @return array table => links retyped (empty once done)
     */
    function kop_resync_keep_underived(PDO $pdo, $force = false) {
        if (!$force && function_exists('get_option') && (int) get_option('kop_resync_keep_underived', 0) >= 1) return array();
        static $ran = false;
        if ($ran && !$force) return array();
        $ran = true;
        $out = array();
        $index = null;
        $sets = array(
            'news_facility_links'    => array('news_submissions', 'news_id'),
            'lawsuit_facility_links' => array('lawsuits', 'lawsuit_id'),
        );
        foreach ($sets as $links => $spec) {
            list($table, $col) = $spec;
            $out[$links] = 0;
            try {
                $rows = $pdo->query("SELECT l.$col AS rid, l.facility_id, r.facilities_mentioned FROM $links l
                    JOIN $table r ON r.id = l.$col WHERE l.link_type = 'mentioned'")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                continue;   // no such table here
            }
            $want = array();
            $retype = $pdo->prepare("UPDATE $links SET link_type = 'related' WHERE $col = ? AND facility_id = ? AND link_type = 'mentioned'");
            foreach ($rows as $r) {
                $rid = (int) $r['rid'];
                if (!isset($want[$rid])) {
                    $want[$rid] = array();
                    foreach (kop_normalize_facility_mentions($r['facilities_mentioned']) as $m) {
                        $fid = !empty($m['facility_id']) ? (int) $m['facility_id'] : null;
                        if (!$fid && $m['name'] !== '') {
                            if ($index === null) $index = kop_build_facility_alias_index($pdo);
                            $fid = kop_resolve_mention_to_facility((string) $m['name'], $index);
                        }
                        if ($fid) $want[$rid][(int) $fid] = true;
                    }
                }
                if (!isset($want[$rid][(int) $r['facility_id']])) {
                    $retype->execute(array($rid, (int) $r['facility_id']));
                    $out[$links] += $retype->rowCount();
                }
            }
        }
        if (function_exists('update_option')) update_option('kop_resync_keep_underived', 1, false);
        return $out;
    }
}

if (!function_exists('kop_resync_news')) {
    /**
     * Re-run every derived step for these news_submissions rows, from the row
     * as it is now. Rows that no longer exist are skipped.
     *
     * @return array id => list of steps that failed (empty when all ran)
     */
    function kop_resync_news(PDO $pdo, $ids, $by = null) {
        kop_resync_keep_underived($pdo);
        $by = ($by === null || $by === '') ? null : (string) $by;
        $report = array();
        $read = $pdo->prepare('SELECT facilities_mentioned FROM news_submissions WHERE id = ?');
        foreach (kop_resync_ids($ids) as $id) {
            $read->execute(array($id));
            $row = $read->fetch(PDO::FETCH_ASSOC);
            $read->closeCursor();
            if (!$row) continue;
            $failed = array();
            $steps = array(
                'facility links' => function () use ($pdo, $id, $row, $by) {
                    kop_sync_news_facility_links($pdo, $id, kop_normalize_facility_mentions($row['facilities_mentioned']), $by);
                },
                'story group'    => function () use ($pdo, $id) { kop_news_assign_story_group($pdo, $id); },
                'story arc'      => function () use ($pdo, $id) { kop_news_assign_story_arc($pdo, $id); },
                'journalists'    => function () use ($pdo, $id) { kop_journalist_sync_article_safe($pdo, $id); },
                // After the facility links: case matching reads them.
                'case links'     => function () use ($pdo, $id, $by) { kop_sync_news_lawsuit_links($pdo, $id, $by); },
            );
            foreach ($steps as $name => $step) {
                try {
                    $step();
                } catch (Throwable $e) {
                    $failed[] = $name;
                    error_log("kop_resync_news($id) $name: " . $e->getMessage());
                }
            }
            $report[$id] = $failed;
        }
        if ($report) kop_record_changed('news', array_keys($report));
        return $report;
    }
}

if (!function_exists('kop_resync_lawsuit')) {
    /**
     * Re-run every derived step for these lawsuits rows. $opts['documents']:
     * also resolve the FileBird folder from the first linked facility when
     * none is set and file the case documents there (what approval does; the
     * media library entries are made, so only when a case goes live or its documents
     * change; only files already in uploads/ are filed, nothing is fetched).
     *
     * @return array id => list of steps that failed
     */
    function kop_resync_lawsuit(PDO $pdo, $ids, $by = null, array $opts = array()) {
        kop_resync_keep_underived($pdo);
        $by = ($by === null || $by === '') ? null : (string) $by;
        $report = array();
        $read = $pdo->prepare('SELECT case_name, facilities_mentioned, document_urls, filebird_folder_id FROM lawsuits WHERE id = ?');
        foreach (kop_resync_ids($ids) as $id) {
            $read->execute(array($id));
            $law = $read->fetch(PDO::FETCH_ASSOC);
            $read->closeCursor();
            if (!$law) continue;
            $failed = array();
            $facilityIds = array();
            try {
                $facilityIds = kop_sync_lawsuit_facility_links($pdo, $id, $law['facilities_mentioned'], $by);
            } catch (Throwable $e) {
                $failed[] = 'facility links';
                error_log("kop_resync_lawsuit($id) facility links: " . $e->getMessage());
            }
            try {
                kop_sync_lawsuit_news_links($pdo, $id, $by);
            } catch (Throwable $e) {
                $failed[] = 'news links';
                error_log("kop_resync_lawsuit($id) news links: " . $e->getMessage());
            }
            if (!empty($opts['documents'])) {
                try {
                    $folderId = (int) ($law['filebird_folder_id'] ?? 0);
                    if (!$folderId && $facilityIds) {
                        $folderId = (int) kop_lawsuit_resolve_facility_folder($pdo, $facilityIds);
                        if ($folderId) {
                            $pdo->prepare('UPDATE lawsuits SET filebird_folder_id = ? WHERE id = ?')->execute(array($folderId, $id));
                        }
                    }
                    if ($folderId && function_exists('kop_lawsuit_file_documents')) {
                        $docs = json_decode((string) $law['document_urls'], true);
                        kop_lawsuit_file_documents(is_array($docs) ? $docs : array(), $folderId, (string) $law['case_name']);
                    }
                } catch (Throwable $e) {
                    $failed[] = 'documents';
                    error_log("kop_resync_lawsuit($id) documents: " . $e->getMessage());
                }
            }
            $report[$id] = $failed;
        }
        if ($report) kop_record_changed('lawsuit', array_keys($report));
        return $report;
    }
}

if (!function_exists('kop_resync_after_status')) {
    /**
     * Rows of a review type ('news', 'lawsuit', 'legislation', 'wiki', as
     * api/manage-submissions.php names them) were set to $status: resync them.
     * A case going to 'published' also files its documents.
     */
    function kop_resync_after_status(PDO $pdo, $type, $ids, $status, $by = null) {
        switch ($type) {
            case 'news':
                return kop_resync_news($pdo, $ids, $by);
            case 'lawsuit':
                return kop_resync_lawsuit($pdo, $ids, $by, array('documents' => $status === 'published'));
            case 'legislation':
                kop_record_changed('bill', kop_resync_ids($ids));
                return array();
            case 'wiki':
                // Approved: blank fields and staff onto the record (once per
                // submission); taken back: the staff it added come off.
                require_once __DIR__ . '/sync-wiki-facilities.php';
                $out = array();
                foreach (kop_resync_ids($ids) as $id) {
                    try {
                        $out[$id] = in_array($status, array('approved', 'published'), true)
                            ? kop_wiki_merge_submission($pdo, $id)
                            : array('staff_removed' => kop_wiki_staff_undo($pdo, $id));
                    } catch (Throwable $e) {
                        error_log("kop_resync_after_status(wiki, $id): " . $e->getMessage());
                    }
                }
                kop_record_changed('wiki', kop_resync_ids($ids));
                return $out;
        }
        return array();
    }
}

if (!function_exists('kop_news_fold_links')) {
    /**
     * Duplicate articles folded into one ($keep stays, $drops are set aside):
     * the facility, case and bill ties made by hand on a dropped copy, and its
     * Essential Reporting pick, move to the kept one unless it has them. Then
     * all of them are resynced. Tables that do not exist yet are skipped.
     */
    function kop_news_fold_links(PDO $pdo, $keep, $drops) {
        $keep = (int) $keep;
        $drops = array_values(array_diff(kop_resync_ids($drops), array($keep)));
        if ($keep <= 0 || !$drops) return;
        $in = implode(',', array_fill(0, count($drops), '?'));
        $tables = array(
            'news_facility_links'    => 'facility_id',
            'lawsuit_news_links'     => 'lawsuit_id',
            'legislation_news_links' => 'legislation_id',
        );
        foreach ($tables as $table => $other) {
            try {
                $rows = $pdo->prepare("SELECT * FROM $table WHERE news_id IN ($in)");
                $rows->execute($drops);
                $has = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE news_id = ? AND $other = ?");
                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $has->execute(array($keep, (int) $r[$other]));
                    $there = (int) $has->fetchColumn();
                    $has->closeCursor();
                    if ($there) continue;
                    $r['news_id'] = $keep;
                    $cols = array_keys($r);
                    $pdo->prepare("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                        ->execute(array_values($r));
                }
            } catch (Throwable $e) {
                // The table does not exist on this database yet.
            }
        }
        try {
            $q = $pdo->prepare('SELECT COUNT(*) FROM news_featured WHERE news_id = ?');
            $q->execute(array($keep));
            if (!(int) $q->fetchColumn()) {
                $f = $pdo->prepare("SELECT news_id FROM news_featured WHERE news_id IN ($in) LIMIT 1");
                $f->execute($drops);
                $from = $f->fetchColumn();
                if ($from) $pdo->prepare('UPDATE news_featured SET news_id = ? WHERE news_id = ?')->execute(array($keep, (int) $from));
            }
        } catch (Throwable $e) {
            // No Essential Reporting table yet.
        }
        kop_resync_news($pdo, array_merge(array($keep), $drops), 'duplicate merge');
    }
}

if (!function_exists('kop_records_deleted')) {
    /**
     * Rows of $type ('news', 'lawsuit', 'bill') were deleted: drop the link rows
     * that pointed at them, so no count or page keeps a link to nothing.
     * Tables that do not exist yet are skipped.
     */
    function kop_records_deleted(PDO $pdo, $type, $ids) {
        $ids = kop_resync_ids($ids);
        if (!$ids) return;
        $cols = array(
            'news'    => array('news_facility_links' => 'news_id', 'lawsuit_news_links' => 'news_id',
                               'legislation_news_links' => 'news_id', 'news_featured' => 'news_id'),
            'lawsuit' => array('lawsuit_facility_links' => 'lawsuit_id', 'lawsuit_news_links' => 'lawsuit_id'),
            'bill'    => array('legislation_news_links' => 'legislation_id'),
        );
        if (!isset($cols[$type])) return;
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach ($cols[$type] as $table => $col) {
            try {
                $pdo->prepare("DELETE FROM $table WHERE $col IN ($in)")->execute($ids);
            } catch (Throwable $e) {
                // The table does not exist on this database yet.
            }
        }
        kop_record_changed($type, $ids, array('deleted' => true));
    }
}
