<?php
/**
 * Staff years that are only the date a wiki page was copied (owner, 2026-10-28).
 *
 * The facts build (kop-pipeline scripts/woodbury-facts.py) read each r/troubledteens wiki page as an issue
 * dated by its copy ("as of 2025-12-18") and gave that date to every person on it: "Former Therapist (2025,
 * r/troubledteens wiki)", "Former Staff (before 2025, r/troubledteens wiki)", and in their past jobs
 * "Founder - Copper Canyon Academy (2025)". The copy date says nothing about when anyone worked anywhere
 * (the build no longer does it). HEAL's years are read from each page and are left alone; so are ranges
 * ("2019-2025": a year the source gave, then the copy).
 *
 * kop_scy_pass() takes those years off once: the role's "(2025, r/troubledteens wiki)" or "(before 2025, ...)"
 * when the entry's source is a wiki copy of that year, and the job's "2025" / "before 2025" when its source
 * is (the job is taken off instead when the person has another job at that place). Roles are logged in
 * the option kop_staff_copy_years_log for kop_scy_undo(); jobs through kop_pjobs_save() (KOP Tools > People >
 * Past jobs, Undo there). Run once by kop_apply_staff_copy_years() (inc/admin.php).
 * Tested by scripts/test-staff-copy-years.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_scy_copy_year')) {
    /** The year of a wiki copy a source names ('r/troubledteens wiki, page "X" (as of 2025-12-18)'), else 0. */
    function kop_scy_copy_year($source) {
        return preg_match('#r/troubledteens wiki\b.*\(as of ((?:19|20)\d{2})-#i', (string) $source, $m) ? (int) $m[1] : 0;
    }

    /** $role without a year that is only its source's copy date: "Former Therapist (2025, r/troubledteens wiki)" -> "Former Therapist". */
    function kop_scy_role($role, $source) {
        $y = kop_scy_copy_year($source);
        if (!$y) return (string) $role;
        return trim(preg_replace('#\s*\((?:before\s+)?' . $y . '(?:,\s*r/troubledteens wiki)?\)\s*$#i', '', (string) $role));
    }

    /** What to do with one job: 'clear' (its years go), 'hide' (another job at the place says it), or '' (leave it). */
    function kop_scy_job_action(array $job, array $same_place_jobs) {
        $y = kop_scy_copy_year($job['source'] ?? '');
        $years = trim((string) ($job['years'] ?? ''));
        if (!$y || ($years !== (string) $y && strcasecmp($years, 'before ' . $y) !== 0)) return '';
        foreach ($same_place_jobs as $o) {
            if ((int) $o['id'] !== (int) $job['id']) return 'hide';
        }
        return 'clear';
    }

    /** A place name for matching jobs: lower case, letters and digits. */
    function kop_scy_place_key($place) {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $place)));
    }

    /**
     * Take the copy-date years off every staff role and past job. Returns {records, roles, jobs_cleared, jobs_hidden}.
     * $opts: pdo + prefix (kop_facility_load/save) as the deploy seeds pass them.
     */
    function kop_scy_pass(array $opts) {
        $out = array('records' => 0, 'roles' => 0, 'jobs_cleared' => 0, 'jobs_hidden' => 0);
        $log = function_exists('get_option') ? (array) get_option('kop_staff_copy_years_log', array()) : array();
        // The write-up queue is not told: a year taken off is not news for the About text.
        $was = $GLOBALS['kop_wu_publishing'] ?? null;
        $GLOBALS['kop_wu_publishing'] = true;
        try {
            $rows = kop_facility_db_rows('SELECT id, json_data FROM ' . kop_facility_table('facilities', $opts), array(), $opts);
            foreach ($rows as $row) {
                $doc = json_decode((string) $row['json_data'], true);
                $hit = false;
                foreach (array('administrator', 'notableStaff') as $l) {
                    foreach ((array) ($doc['staff'][$l] ?? array()) as $p) {
                        if (is_array($p) && kop_scy_role($p['role'] ?? '', $p['source'] ?? '') !== (string) ($p['role'] ?? '')) $hit = true;
                    }
                }
                if (!$hit) continue;
                $id = (int) $row['id'];
                $changes = array();
                kop_v2_with_write_lock($opts['pdo'], function () use ($id, $opts, &$changes) {
                    $stored = kop_facility_load($id, $opts);
                    if (!$stored) return;
                    $legacy = kop_facility_to_legacy($stored['doc']);
                    foreach (array('administrator', 'notableStaff') as $l) {
                        foreach ((array) ($legacy['staff'][$l] ?? array()) as $i => $p) {
                            if (!is_array($p)) continue;
                            $new = kop_scy_role($p['role'] ?? '', $p['source'] ?? '');
                            if ($new === (string) ($p['role'] ?? '')) continue;
                            $changes[] = array('fid' => $id, 'name' => (string) ($p['name'] ?? ''), 'before' => (string) $p['role'], 'after' => $new);
                            $legacy['staff'][$l][$i]['role'] = $new;
                        }
                    }
                    if (!$changes) return;
                    $doc = kop_facility_normalize($legacy, array('facility_id' => $stored['id'], 'unique_name' => $stored['unique_name']));
                    $doc['provenance'] = $stored['doc']['provenance'];
                    kop_facility_save($doc, $opts + array('skip_memberships' => true));
                });
                if ($changes) {
                    $out['records']++;
                    $out['roles'] += count($changes);
                    foreach ($changes as $c) $log[] = $c;
                }
            }
            if (function_exists('kop_pjobs_ready') && kop_pjobs_ready($opts)) {
                $by_place = array();
                foreach (kop_facility_db_rows('SELECT id, person_id, place, years, source FROM ' . kop_pjobs_table($opts) . ' WHERE hidden = 0 ORDER BY id', array(), $opts) as $j) {
                    $by_place[(int) $j['person_id'] . '|' . kop_scy_place_key($j['place'])][] = $j;
                }
                foreach ($by_place as $jobs) {
                    $left = $jobs;
                    foreach ($jobs as $j) {
                        $act = kop_scy_job_action($j, $left);
                        if ($act === '') continue;
                        kop_pjobs_save((int) $j['person_id'], (int) $j['id'], $act === 'hide' ? array('hidden' => 1) : array('years' => ''), $opts);
                        $log[] = array('job' => (int) $j['id'], 'person' => (int) $j['person_id'], 'act' => $act, 'years' => (string) $j['years']);
                        if ($act === 'hide') {
                            $out['jobs_hidden']++;
                            $left = array_values(array_filter($left, function ($o) use ($j) { return (int) $o['id'] !== (int) $j['id']; }));
                        } else {
                            $out['jobs_cleared']++;
                        }
                    }
                }
            }
        } finally {
            $GLOBALS['kop_wu_publishing'] = $was;
        }
        if (function_exists('update_option')) update_option('kop_staff_copy_years_log', $log, false);
        return $out;
    }

    /** Put back every role and job the pass changed that nobody has changed since. Returns the number put back. */
    function kop_scy_undo(array $opts) {
        $log = function_exists('get_option') ? (array) get_option('kop_staff_copy_years_log', array()) : array();
        $n = 0;
        $roles = array();
        foreach ($log as $e) {
            if (isset($e['fid'])) {
                $roles[(int) $e['fid']][] = $e;
            } elseif (isset($e['job'])) {
                kop_pjobs_save((int) $e['person'], (int) $e['job'], $e['act'] === 'hide' ? array('hidden' => 0) : array('years' => $e['years']), $opts);
                $n++;
            }
        }
        foreach ($roles as $fid => $list) {
            kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $list, $opts, &$n) {
                $stored = kop_facility_load($fid, $opts);
                if (!$stored) return;
                $legacy = kop_facility_to_legacy($stored['doc']);
                $hit = false;
                foreach ($list as $e) {
                    foreach (array('administrator', 'notableStaff') as $l) {
                        foreach ((array) ($legacy['staff'][$l] ?? array()) as $i => $p) {
                            if (is_array($p) && ($p['name'] ?? '') === $e['name'] && (string) ($p['role'] ?? '') === $e['after']) {
                                $legacy['staff'][$l][$i]['role'] = $e['before'];
                                $hit = true;
                                $n++;
                                continue 3;
                            }
                        }
                    }
                }
                if (!$hit) return;
                $doc = kop_facility_normalize($legacy, array('facility_id' => $stored['id'], 'unique_name' => $stored['unique_name']));
                $doc['provenance'] = $stored['doc']['provenance'];
                kop_facility_save($doc, $opts + array('skip_memberships' => true));
            });
        }
        if (function_exists('update_option')) update_option('kop_staff_copy_years_log', array(), false);
        return $n;
    }
}
