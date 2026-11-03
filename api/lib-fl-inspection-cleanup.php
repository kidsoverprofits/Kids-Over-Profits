<?php
/**
 * Move the Florida DJJ reports the old fl_scraper.py matcher filed under the
 * wrong facility (seeds/fl-inspection-cleanup.json, built by kop-pipeline
 * scripts/build-fl-djj-refile.py; docs/plan/4.2-inspection-reports.md item 10).
 *
 * The old matcher dropped "academy", "detention", "girls", "group home"... from
 * names and took one-word overlaps, so "Marion Youth Academy" reports sat on
 * Marion Regional Juvenile Detention Center, Lake Academy's on Lake Parrish
 * Youth Academy, and runs that could not read DJJ's directory left a
 * DJJ-unmatched-* twin of each facility. Each move names the report row, the
 * facility it is on and the one the fixed matcher posts it to (an existing row,
 * or one to create with the name and DJJ-<slug> program the scraper uses, so a
 * later run upserts onto it instead of adding a twin):
 *
 *   - the row and its findings and counts move to the target facility; a
 *     report about several facilities (a joint QI report, the "JUST / Twin
 *     Oaks Academy II" audit) stays on (or moves to) one of them and each
 *     other one gets a copy of it
 *   - when the target already holds the same report_id, the target's copy
 *     stays and takes the reviews the moved copy had; the moved copy goes
 *   - a DJJ-unmatched-* facility left with no reports goes; Inspection Links
 *     to it follow its reports when they all went to one facility
 *
 * A row that changed since the plan (gone, on another facility, another
 * report_id) is skipped. Portable SQL, so the test runs it on SQLite.
 * The generic row helpers are the NC cleanup's.
 */

require_once __DIR__ . '/lib-nc-inspection-cleanup.php';

function kop_flclean_facility(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT id, facility_name, program_name FROM inspection_facilities WHERE id = ? AND state = 'FL'");
    $st->execute(array((int) $id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The FL row with this name and program (the write API's identity), or null. */
function kop_flclean_find(PDO $pdo, $name, $program) {
    $st = $pdo->prepare("SELECT id, facility_name, program_name FROM inspection_facilities
                          WHERE state = 'FL' AND LOWER(facility_name) = LOWER(?) AND LOWER(program_name) = LOWER(?) ORDER BY id LIMIT 1");
    $st->execute(array((string) $name, (string) $program));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Everything the plan touches, for the backup file. */
function kop_flclean_backup(PDO $pdo, array $plan) {
    $ids = array();
    foreach ((array) ($plan['moves'] ?? array()) as $m) {
        $ids[] = (int) $m['from']['id'];
        if (!empty($m['to']['id'])) $ids[] = (int) $m['to']['id'];
    }
    $ids = array_values(array_unique($ids));
    $report_ids = array_map(function ($m) { return (int) $m['id']; }, (array) ($plan['moves'] ?? array()));
    $out = array(
        'facilities' => kop_ncclean_rows($pdo, 'inspection_facilities', 'id', $ids),
        // The moved rows' places (not their text, which does not change).
        'reports'    => array_map(function ($r) { return array('id' => $r['id'], 'facility_id' => $r['facility_id'], 'report_id' => $r['report_id']); },
                                  kop_ncclean_rows($pdo, 'inspection_reports', 'id', $report_ids)),
        'highlights' => kop_ncclean_rows($pdo, 'inspection_highlights', 'report_id', $report_ids),
    );
    try { $out['inspection_report_counts'] = kop_ncclean_rows($pdo, 'inspection_report_counts', 'report_id', $report_ids); } catch (Throwable $e) { $out['inspection_report_counts'] = array(); }
    return $out;
}

/** Fold the moved copy ($rid) into the target's own copy ($twin) of the same report. */
function kop_flclean_fold(PDO $pdo, $rid, $twin, $target_fid, array &$stats) {
    $hl = $pdo->prepare('SELECT * FROM inspection_highlights WHERE report_id = ?');
    $hl->execute(array((int) $rid));
    foreach ($hl->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $q = $pdo->prepare('SELECT id, status FROM inspection_highlights WHERE report_id = ? AND finding_key = ?');
        $q->execute(array((int) $twin, $h['finding_key']));
        $t = $q->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            $pdo->prepare('UPDATE inspection_highlights SET report_id = ?, facility_id = ? WHERE id = ?')
                ->execute(array((int) $twin, (int) $target_fid, (int) $h['id']));
            $stats['findings_moved']++;
        } elseif ($t['status'] === 'pending' && $h['status'] !== 'pending') {
            $pdo->prepare('UPDATE inspection_highlights SET status = ?, reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?')
                ->execute(array($h['status'], $h['reviewed_by'], $h['reviewed_at'], $h['review_note'], (int) $t['id']));
            $stats['reviews_kept']++;
        }
    }
    kop_ncclean_drop_reports($pdo, array((int) $rid));
}

/** The facility id a plan target names (creating it when $apply), null when it changed. */
function kop_flclean_target(PDO $pdo, array $t, $apply, array &$created, array &$stats) {
    if (!empty($t['id'])) {
        $to = kop_flclean_facility($pdo, $t['id']);
        return ($to && strcasecmp($to['program_name'], $t['program_name']) === 0) ? (int) $to['id'] : null;
    }
    $c = (array) ($t['create'] ?? array());
    if (empty($c['program_name'])) return null;
    $key = strtolower($c['facility_name'] . '|' . $c['program_name']);
    if (!isset($created[$key])) {
        $have = kop_flclean_find($pdo, $c['facility_name'], $c['program_name']);
        if ($have) {
            $created[$key] = (int) $have['id'];
        } elseif ($apply) {
            $pdo->prepare("INSERT INTO inspection_facilities (state, facility_name, program_name, program_category) VALUES ('FL', ?, ?, ?)")
                ->execute(array($c['facility_name'], $c['program_name'], (string) ($c['program_category'] ?? '')));
            $created[$key] = (int) $pdo->lastInsertId();
            $stats['facilities_created']++;
        } else {
            $created[$key] = -(count($created) + 1);
            $stats['facilities_created']++;
        }
    }
    return $created[$key];
}

/** The row id of this report on facility $fid (held, or placed earlier in this run), else false. */
function kop_flclean_holds(PDO $pdo, PDOStatement $twin_q, array $placed, $fid, $report_id, $not_id) {
    if (isset($placed[$fid . '|' . $report_id])) return $placed[$fid . '|' . $report_id];
    if ($fid <= 0) return false;
    $twin_q->execute(array($fid, $report_id, (int) $not_id));
    return $twin_q->fetchColumn();
}

/** A copy of report row $rid on facility $fid (every column but the id and the stamps); its new id. */
function kop_flclean_copy(PDO $pdo, $rid, $fid) {
    $st = $pdo->prepare('SELECT * FROM inspection_reports WHERE id = ?');
    $st->execute(array((int) $rid));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    unset($row['id'], $row['created_at'], $row['updated_at']);
    $row['facility_id'] = (int) $fid;
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO inspection_reports (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));
    return (int) $pdo->lastInsertId();
}

/**
 * Apply (or, with $apply false, only check) the plan. Returns
 * array('stats' => counts, 'skipped' => reasons, 'removed' => facility ids, 'merged' => removed id => facility its reports went to).
 */
function kop_flclean_run(PDO $pdo, array $plan, $apply) {
    $stats = array('moved' => 0, 'copied' => 0, 'twins_folded' => 0, 'findings_moved' => 0, 'reviews_kept' => 0,
                   'facilities_created' => 0, 'empty_facilities_removed' => 0);
    $skipped = array();
    $created = array();      // lower(name|program) => id (negative in a dry run)
    $went = array();         // from facility id => [target id => n]
    $left = array();         // from facility id => reports moved off it
    $placed = array();       // "target|report_id" => row id the run put there

    if ($apply) $pdo->beginTransaction();
    try {
        $row_q = $pdo->prepare('SELECT id, facility_id, report_id FROM inspection_reports WHERE id = ?');
        $twin_q = $pdo->prepare('SELECT id FROM inspection_reports WHERE facility_id = ? AND report_id = ? AND id <> ?');
        foreach ((array) ($plan['moves'] ?? array()) as $m) {
            $rid = (int) $m['id'];
            $row_q->execute(array($rid));
            $row = $row_q->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) $row['facility_id'] !== (int) $m['from']['id'] || (string) $row['report_id'] !== (string) $m['report_id']) {
                $skipped[] = "report $rid: gone or moved since the plan"; continue;
            }
            $from = kop_flclean_facility($pdo, $m['from']['id']);
            if (!$from || $from['program_name'] !== $m['from']['program_name']) { $skipped[] = "report $rid: facility {$m['from']['id']} changed"; continue; }

            // Every facility the report is about; a plan of the first shape had one ('to' => {...}).
            $targets = isset($m['to']['id']) || isset($m['to']['create']) ? array($m['to']) : (array) $m['to'];
            $tids = array();
            foreach ($targets as $t) {
                $tid = kop_flclean_target($pdo, $t, $apply, $created, $stats);
                if ($tid === null) { $skipped[] = "report $rid: target " . ($t['id'] ?? $t['create']['program_name']) . ' changed or gone'; $tids = array(); break; }
                if (!in_array($tid, $tids, true)) $tids[] = $tid;
            }
            if (!$tids) continue;
            $fid = (int) $from['id'];
            $home = in_array($fid, $tids, true) ? $fid : $tids[0];

            if ($home !== $fid) {
                // The target's own copy: one it held, or (seen only here in a dry run) one an earlier move put there.
                $twin = kop_flclean_holds($pdo, $twin_q, $placed, $home, (string) $row['report_id'], $rid);
                if ($twin) {
                    if ($apply) kop_flclean_fold($pdo, $rid, (int) $twin, $home, $stats);
                    $stats['twins_folded']++;
                    $rid_now = (int) $twin;
                } else {
                    $placed[$home . '|' . $row['report_id']] = $rid;
                    if ($apply) {
                        $pdo->prepare('UPDATE inspection_reports SET facility_id = ? WHERE id = ?')->execute(array($home, $rid));
                        $pdo->prepare('UPDATE inspection_highlights SET facility_id = ? WHERE report_id = ?')->execute(array($home, $rid));
                        try { $pdo->prepare('UPDATE inspection_report_counts SET facility_id = ? WHERE report_id = ?')->execute(array($home, $rid)); } catch (Throwable $e) {}
                    }
                    $stats['moved']++;
                    $rid_now = $rid;
                }
                $went[$fid][$home] = ($went[$fid][$home] ?? 0) + 1;
                $left[$fid] = ($left[$fid] ?? 0) + 1;
            } else {
                $placed[$home . '|' . $row['report_id']] = $rid;
                $rid_now = $rid;
            }

            // The other facilities it is about get a copy of the report (not of its findings: the scan reads the copy).
            foreach ($tids as $tid) {
                if ($tid === $home || kop_flclean_holds($pdo, $twin_q, $placed, $tid, (string) $row['report_id'], 0)) continue;
                $new_id = $apply ? kop_flclean_copy($pdo, $rid_now, $tid) : -$rid;
                $placed[$tid . '|' . $row['report_id']] = $new_id;
                $stats['copied']++;
            }
        }

        // DJJ-unmatched-* facilities the moves emptied.
        $removed = array();
        $merged = array();
        $count_q = $pdo->prepare('SELECT COUNT(*) FROM inspection_reports WHERE facility_id = ?');
        foreach ($left as $fid => $n) {
            $f = kop_flclean_facility($pdo, $fid);
            if (!$f || stripos($f['program_name'], 'DJJ-unmatched-') !== 0) continue;
            $count_q->execute(array($fid));
            $still = (int) $count_q->fetchColumn() - ($apply ? 0 : $n);
            if ($still > 0) continue;
            if ($apply) {
                $pdo->prepare('DELETE FROM inspection_highlights WHERE facility_id = ?')->execute(array($fid));
                $pdo->prepare('DELETE FROM inspection_facilities WHERE id = ?')->execute(array($fid));
            }
            $stats['empty_facilities_removed']++;
            $removed[] = $fid;
            $targets = array_keys($went[$fid]);
            if (count($targets) === 1 && $targets[0] > 0) $merged[$fid] = (int) $targets[0];
        }
        if ($apply) $pdo->commit();
    } catch (Throwable $e) {
        if ($apply && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // Inspection Links: a removed row whose reports all went to one facility follows them.
    $gone = array_values(array_diff($removed, array_keys($merged)));
    return array('stats' => $stats, 'skipped' => $skipped, 'removed' => $gone, 'merged' => $merged);
}
