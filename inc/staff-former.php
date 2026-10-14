<?php
/**
 * Who on a facility's staff lists has left. The record has no "former" field: an entry says so in its
 * role, as "Former <role>" (kop_wiki_staff_record_role() does the same for the r/troubledteens wiki).
 *
 * A person is former when the program is closed (everyone on a closed program's staff lists has left),
 * when the role says they left (", left", the Woodbury build's old wording), or when the role is only a
 * dated sighting ("Admissions (2007, Woodbury Reports)", "Teacher (2017, HEAL)") whose latest year is
 * more than a year before this one: a 2008 issue naming someone is no sign they work there now.
 * "(current)" in a dated role is the source's word at the time and goes.
 *
 * Woodbury Facts (and Fornits, through it) applies this when it adds a person; kop_staff_former_pass()
 * rewrote the entries already on records (api/fix-staff-former.php), logged in the option
 * kop_staff_former_log for an exact undo. Wiki entries are left to the wiki's own pass (its log matches
 * entries by role).
 */

if (!function_exists('kop_staff_role_with_former')) {
    /**
     * The latest year a dated sighting names, 0 when none: "(2008, Woodbury Reports)", "(2021, HEAL)", and the
     * wiki and Fornits items Woodbury Facts adds ("(2005, r/troubledteens wiki)").
     */
    function kop_staff_role_sighting_year($role) {
        if (!preg_match_all('/\(([^()]*\b(?:19|20)\d{2}\b[^()]*),\s*(?:Woodbury Reports|HEAL|r\/troubledteens wiki|Fornits)[^()]*\)/i', (string) $role, $m)) return 0;
        $year = 0;
        foreach ($m[1] as $part) {
            preg_match_all('/\b(?:19|20)\d{2}\b/', $part, $y);
            foreach ($y[0] as $v) $year = max($year, (int) $v);
        }
        return $year;
    }

    /**
     * $role as the record should hold it: "Former " before it when the person has left (see above).
     * $closed: the program is closed. $now: this year (tests pass one).
     */
    function kop_staff_role_with_former($role, $closed = false, $now = null) {
        $role = trim(preg_replace('/\s+/u', ' ', (string) $role));
        if ($role === '') return '';
        $now = $now ?: (int) gmdate('Y');
        $left = (bool) preg_match('/,\s*left\s*$/i', $role);
        if ($left) $role = trim(preg_replace('/,\s*left\s*$/i', '', $role));
        $year = kop_staff_role_sighting_year($role);
        if ($year) $role = trim(preg_replace('/\s*\(current\)/i', '', $role));
        if (preg_match('/^(?:former|previous|ex-)\b/i', $role)) return $role;
        // A role that says only part of it is past ("Founder, former Executive Director") is left as written.
        if (preg_match('/\b(?:former|previous|ex-)/i', $role) && !$left && !$closed) return $role;
        if ($closed || $left || ($year && $year < $now - 1)) return 'Former ' . $role;
        return $role;
    }

    /** Is the program closed, by its status or an end year (kop_facility_save()'s operatingPeriod)? */
    function kop_staff_doc_closed(array $doc) {
        $p = (array) ($doc['operatingPeriod'] ?? array());
        return (bool) preg_match('/\bclosed\b/i', (string) ($p['status'] ?? '')) || !empty($p['endYear']);
    }

    /**
     * Staff entries that are not people (2026-10-14, every live record checked): HEAL's reader took
     * Moonridge Academy's phase sheet for a staff list, a licence lookup line at Bethel Bible Village and a
     * news headline at Advancing Youth's Academy. facility id => names, exact.
     */
    function kop_staff_not_people() {
        return array(
            10343 => array('1 10-minute weekly social call to approved friend', '1 overnight (First night of Parent Weekend)',
                '2 10 minute weekly social phone call to family members', '2 off campus activities in a month',
                '2 overnights (First and last night of Parent Weekend)', '5 hours off campus on the day after Parent Weekend',
                '5-hours on campus', 'ATTEND CHURCH OFF CAMPUS', 'Off campus lunch privileges', 'On-campus privileges only',
                'Overnights in Cedar City', 'Receive mail from one approved friend', 'Receive mail', 'Send mail', 'Street Clothing',
                'Worn down', 'Worn pulled away from the face'),
            11267 => array('FLOYD , RICHARD MANLEY KNOXVILLE, TN 37923 Profession: Emergency Medical Responder Rank: Unapproved Applicant'),
            12497 => array('2 Investigates: Police raid Roswell school for troubled teens'),
        );
    }

    /** A name an importer must not add as a person: digits, a colon, or more than six words ("2 off campus activities"). */
    function kop_staff_name_not_person($name) {
        $name = trim((string) $name);
        return $name === '' || preg_match('/\d|:/', $name) || count(preg_split('/\s+/', $name)) > 6;
    }

    /**
     * Take the kop_staff_not_people() entries off their records ($undo: put each back where it was).
     * Logged in the option kop_staff_not_people_log (facility id => [{list, index, entry}]).
     * Returns [{id, list, name}] of what was (or would be) taken off or put back.
     */
    function kop_staff_not_people_pass(array $opts, $apply = false, $undo = false) {
        $get = function () { return (array) (function_exists('get_option') ? get_option('kop_staff_not_people_log', array()) : ($GLOBALS['kop_staff_not_people_log'] ?? array())); };
        $log = $get();
        $out = array();
        $targets = $undo ? array_keys($log) : array_keys(kop_staff_not_people());
        foreach ($targets as $id) {
            $id = (int) $id;
            $stored = kop_facility_load($id, $opts);
            if (!$stored) continue;
            $d = $stored['doc'];
            $changed = array();
            if ($undo) {
                // Back where each was, lowest index first.
                $items = (array) $log[$id];
                usort($items, function ($a, $b) { return $a['index'] - $b['index']; });
                foreach ($items as $it) {
                    $list = (array) ($d['staff'][$it['list']] ?? array());
                    array_splice($list, min((int) $it['index'], count($list)), 0, array($it['entry']));
                    $d['staff'][$it['list']] = $list;
                    $changed[] = array('id' => $id, 'list' => $it['list'], 'name' => (string) $it['entry']['name']);
                }
            } else {
                $names = array_flip(kop_staff_not_people()[$id]);
                $taken = array();
                foreach (array('administrator', 'notableStaff') as $l) {
                    $keep = array();
                    foreach ((array) ($d['staff'][$l] ?? array()) as $i => $e) {
                        if (is_array($e) && isset($names[trim((string) ($e['name'] ?? ''))])) {
                            $taken[] = array('list' => $l, 'index' => $i, 'entry' => $e);
                            $changed[] = array('id' => $id, 'list' => $l, 'name' => (string) $e['name']);
                            continue;
                        }
                        $keep[] = $e;
                    }
                    $d['staff'][$l] = $keep;
                }
                if ($taken) $log[$id] = array_merge((array) ($log[$id] ?? array()), $taken);
            }
            if (!$changed) continue;
            $out = array_merge($out, $changed);
            if (!$apply) continue;
            kop_v2_with_write_lock($opts['pdo'], function () use ($opts, $stored, $d) {
                $doc = kop_facility_normalize(kop_facility_to_legacy($d), array('facility_id' => $stored['id'], 'unique_name' => $stored['unique_name']));
                $doc['provenance'] = $stored['doc']['provenance'];
                kop_facility_save($doc, $opts + array('skip_memberships' => true));
            });
            if ($undo) unset($log[$id]);
        }
        if ($apply) {
            if (function_exists('update_option')) update_option('kop_staff_not_people_log', $log, false);
            else $GLOBALS['kop_staff_not_people_log'] = $log;
        }
        return $out;
    }

    /**
     * Once a year (the first daily run of a new year): sightings from the year before last turn "Former"
     * ("Teacher (2025, HEAL)" in 2027). Each run's changes join kop_staff_former_log, so undo still works.
     */
    function kop_staff_former_yearly() {
        $year = (int) gmdate('Y');
        if ((int) get_option('kop_staff_former_year', 0) >= $year || !function_exists('kop_seed_pdo') || !function_exists('kop_seed_v2_prefix')) return;
        $pdo = kop_seed_pdo();
        $prefix = $pdo ? kop_seed_v2_prefix($pdo) : null;
        if (!$pdo || $prefix === null) return;
        try {
            $done = kop_staff_former_pass(array('pdo' => $pdo, 'prefix' => $prefix), true);
            update_option('kop_staff_former_year', $year, false);
            if ($done) error_log('kop_staff_former_yearly: ' . count($done) . ' staff roles marked Former');
        } catch (Throwable $e) {
            error_log('kop_staff_former_yearly: ' . $e->getMessage());
        }
    }

    /** Entries the pass leaves alone: wiki staff (their own pass and log), and entries with no role. */
    function kop_staff_former_skip($entry) {
        return !is_array($entry) || trim((string) ($entry['role'] ?? '')) === '' || ($entry['source'] ?? '') === 'r/troubledteens wiki';
    }

    /**
     * Rewrite the roles of every staff entry already on a record by kop_staff_role_with_former(), or put
     * them back ($undo: each logged entry still holding the role the pass gave it gets its role before).
     * $opts: {pdo, prefix} for kop_facility_load()/kop_facility_save(). $apply false = say what would
     * change. Returns [{id, name, list, from, to, why}].
     */
    function kop_staff_former_pass(array $opts, $apply = false, $undo = false, $now = null) {
        $pdo = $opts['pdo'];
        $log = $undo || $apply ? (array) (function_exists('get_option') ? get_option('kop_staff_former_log', array()) : ($GLOBALS['kop_staff_former_log'] ?? array())) : array();
        if ($undo && !$log) return array();
        $out = array();
        $rows = $pdo->query('SELECT id, json_data FROM ' . kop_facility_table('facilities', $opts))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($undo && empty($log[$id])) continue;
            $doc = json_decode((string) $row['json_data'], true);
            if (!is_array($doc)) continue;
            $closed = kop_staff_doc_closed($doc);
            $changes = array();
            foreach (array('administrator', 'notableStaff') as $list) {
                foreach ((array) ($doc['staff'][$list] ?? array()) as $i => $e) {
                    if (kop_staff_former_skip($e)) continue;
                    $role = (string) $e['role'];
                    if ($undo) {
                        foreach ((array) $log[$id] as $l) {
                            if ($l['list'] === $list && $l['name'] === (string) ($e['name'] ?? '') && $l['to'] === $role) {
                                $changes[] = array('list' => $list, 'i' => $i, 'name' => $l['name'], 'from' => $role, 'to' => $l['from'], 'why' => 'undo');
                                break;
                            }
                        }
                        continue;
                    }
                    $new = kop_staff_role_with_former($role, $closed, $now);
                    if ($new === $role) continue;
                    $why = $closed ? 'closed' : (preg_match('/,\s*left\s*$/i', $role) ? 'left' : (strpos($new, 'Former ') === 0 && stripos($role, 'former') !== 0 ? 'old sighting' : 'wording'));
                    $changes[] = array('list' => $list, 'i' => $i, 'name' => (string) ($e['name'] ?? ''), 'from' => $role, 'to' => $new, 'why' => $why);
                }
            }
            if (!$changes) continue;
            foreach ($changes as $c) $out[] = array('id' => $id) + array_diff_key($c, array('i' => 1));
            if (!$apply) continue;
            kop_v2_with_write_lock($pdo, function () use ($id, $opts, $changes) {
                $stored = kop_facility_load($id, $opts);
                if (!$stored) return;
                $d = $stored['doc'];
                foreach ($changes as $c) {
                    if (isset($d['staff'][$c['list']][$c['i']]) && (string) ($d['staff'][$c['list']][$c['i']]['role'] ?? '') === $c['from']) {
                        $d['staff'][$c['list']][$c['i']]['role'] = $c['to'];
                    }
                }
                $doc = kop_facility_normalize(kop_facility_to_legacy($d), array('facility_id' => $stored['id'], 'unique_name' => $stored['unique_name']));
                $doc['provenance'] = $stored['doc']['provenance'];
                kop_facility_save($doc, $opts + array('skip_memberships' => true));
            });
            if ($undo) {
                unset($log[$id]);
            } else {
                foreach ($changes as $c) {
                    // The first role an entry had is kept, so undo after two runs still goes back to it.
                    $known = false;
                    foreach ((array) ($log[$id] ?? array()) as $k => $l) {
                        if ($l['list'] === $c['list'] && $l['name'] === $c['name'] && $l['to'] === $c['from']) {
                            $log[$id][$k]['to'] = $c['to'];
                            $known = true;
                        }
                    }
                    if (!$known) $log[$id][] = array('list' => $c['list'], 'name' => $c['name'], 'from' => $c['from'], 'to' => $c['to']);
                }
            }
        }
        if ($apply) {
            if (function_exists('update_option')) update_option('kop_staff_former_log', $log, false);
            else $GLOBALS['kop_staff_former_log'] = $log;
        }
        return $out;
    }
}

if (function_exists('add_action')) {
    add_action('init', function () {
        if (!wp_next_scheduled('kop_staff_former_daily')) wp_schedule_event(time() + 3600, 'daily', 'kop_staff_former_daily');
    }, 30);
    add_action('kop_staff_former_daily', 'kop_staff_former_yearly');
}
