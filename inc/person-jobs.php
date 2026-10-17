<?php
/**
 * A person's past jobs, held once on the person (owner, 2026-10-17: "the people table should hold past
 * roles for staff so that we don't have to update them on every profile").
 *
 *   {prefix}kop_person_jobs   one row per job: person_id (kop_people), place (as written: a program,
 *                             company or anything else), role, years ("2008-2011", "before 2013"),
 *                             source + source_url, origin ('pastjobs' = read from a staff entry's
 *                             pastJobs text, 'manual' = added on KOP Tools > People), import_key
 *                             (the text it was read from, so it is read once), hidden (taken off by
 *                             an admin: kept so the text never brings it back).
 *   option kop_person_jobs_log  every change on the People screen, {at, by, id, before, after}, for Undo.
 *
 * The staff entries keep their pastJobs text (the forms, Woodbury Facts and the wiki still write it):
 * the hourly people sync (kop_people_sync() -> kop_pjobs_import()) folds any new text into the person's
 * jobs, once. Every page that lists the person (facility and company pages, their careers, the app)
 * reads the jobs from here through kop_facility_pages_person_career(), so a job added or fixed once
 * shows on every profile. A person merged into another brings their jobs (read by resolved id).
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_PERSON_JOBS_DB_VERSION', '1');

if (!function_exists('kop_pjobs_table')) {
    function kop_pjobs_table(array $opts = array()) {
        $prefix = isset($opts['prefix']) ? (string) $opts['prefix'] : (isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '');
        return $prefix . 'kop_person_jobs';
    }

    /** Creates the table (MySQL) once per KOP_PERSON_JOBS_DB_VERSION. */
    function kop_pjobs_install($force = false) {
        if (!$force && get_option('kop_person_jobs_db') === KOP_PERSON_JOBS_DB_VERSION) return;
        global $wpdb;
        $t = kop_pjobs_table();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id BIGINT UNSIGNED NOT NULL,
            place VARCHAR(255) NOT NULL DEFAULT '',
            role VARCHAR(255) NOT NULL DEFAULT '',
            years VARCHAR(64) NOT NULL DEFAULT '',
            source VARCHAR(255) NOT NULL DEFAULT '',
            source_url VARCHAR(500) NOT NULL DEFAULT '',
            origin VARCHAR(12) NOT NULL DEFAULT 'manual',
            import_key VARCHAR(64) NOT NULL DEFAULT '',
            hidden TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY person_id (person_id),
            KEY import_key (import_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        update_option('kop_person_jobs_db', KOP_PERSON_JOBS_DB_VERSION, false);
    }
    if (function_exists('add_action')) add_action('admin_init', 'kop_pjobs_install');

    /** Is the table there (a site before its first admin load, a test without it)? */
    function kop_pjobs_ready(array $opts = array()) {
        static $ready = array();
        $opts = $opts ?: (array) ($GLOBALS['kop_pjobs_opts'] ?? array());   // tests: a SQLite copy
        $t = kop_pjobs_table($opts);
        if (!isset($ready[$t])) {
            if (empty($opts['pdo']) && isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'get_var')) {
                // $wpdb reports a missing table as an empty result, not an error.
                $ready[$t] = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SHOW TABLES LIKE %s', $t)) === $t;
            } else {
                try {
                    kop_facility_db_rows("SELECT id FROM {$t} LIMIT 1", array(), $opts);
                    $ready[$t] = true;
                } catch (Throwable $e) {
                    $ready[$t] = false;
                }
            }
        }
        return $ready[$t];
    }
}

if (!function_exists('kop_pjobs_parse')) {
    /**
     * A pastJobs text as jobs: "Admissions Director - Sunrise Academy (2007); later Director - CARE (2008)
     * [joined 2008-12-15]" -> [{role, place, years}]. The facility pages' reading of it, kept as it was.
     */
    function kop_pjobs_parse($text) {
        $text = preg_replace('/\s*\[[^\]]*\]\s*/', ' ', (string) $text);
        $out = array();
        foreach (preg_split('/\s*;\s*/', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $job) {
            $job = trim(preg_replace('/^(?:later|then|now|previously|formerly)\s+/i', '', trim($job)));
            $role = '';
            $place = $job;
            $pos = strrpos($job, ' - ');
            if ($pos !== false) {
                $role = trim(substr($job, 0, $pos));
                $place = trim(substr($job, $pos + 3));
            } elseif (preg_match('/^(.+?),\s+([^,]+)$/', $job, $m) && !preg_match('/\d{4}/', $m[2])) {
                // The wiki's "Therapist, Provo Canyon School".
                $role = trim($m[1]);
                $place = trim($m[2]);
            }
            $years = '';
            if (preg_match('/\s*\(([^)]*\d{4}[^)]*)\)\s*$/', $place, $m)) {
                $years = trim($m[1]);
                $place = trim(substr($place, 0, -strlen($m[0])));
            }
            if ($place === '' || preg_match('/\]\(|https?:\/\//', $place)) continue;
            $out[] = array('role' => mb_substr($role, 0, 255), 'place' => mb_substr($place, 0, 255), 'years' => mb_substr($years, 0, 64));
        }
        return $out;
    }

    /** Was this job of this text read in already (shown, or taken off since)? */
    function kop_pjobs_known($person_id, array $job, array $opts = array()) {
        $opts = $opts ?: (array) ($GLOBALS['kop_pjobs_opts'] ?? array());
        if (!isset($GLOBALS['kop_pjobs_keys']) || !is_array($GLOBALS['kop_pjobs_keys'])) {
            $GLOBALS['kop_pjobs_keys'] = array();
            if (kop_pjobs_ready($opts)) {
                foreach (kop_facility_db_rows('SELECT import_key FROM ' . kop_pjobs_table($opts) . " WHERE import_key <> ''", array(), $opts) as $r) $GLOBALS['kop_pjobs_keys'][$r['import_key']] = true;
            }
        }
        return isset($GLOBALS['kop_pjobs_keys'][kop_pjobs_key($person_id, $job)]);
    }

    /** The import key of a job read from text: the person and the job's own words, so the same job is read once. */
    function kop_pjobs_key($person_id, array $job) {
        $fold = function ($s) { return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $s))); };
        return sha1((int) $person_id . '|' . $fold($job['place']) . '|' . $fold($job['role']) . '|' . $fold($job['years']));
    }
}

if (!function_exists('kop_pjobs_import')) {
    /**
     * Folds every staff entry's pastJobs text into its person's jobs: each job not read before (by
     * kop_pjobs_key(), hidden ones included) gets a row citing the entry's source. Entries with no
     * person id wait for the sync to give them one. Returns the number of rows added.
     */
    function kop_pjobs_import(array $opts = array()) {
        if (!kop_pjobs_ready($opts)) return 0;
        $t = kop_pjobs_table($opts);
        $seen = array();
        foreach (kop_facility_db_rows("SELECT import_key FROM {$t} WHERE import_key <> ''", array(), $opts) as $r) $seen[$r['import_key']] = true;
        $facilities = kop_facility_table('facilities', $opts);
        $add = array();
        foreach (kop_facility_db_rows("SELECT id, json_data FROM {$facilities} ORDER BY id", array(), $opts) as $row) {
            $doc = json_decode((string) $row['json_data'], true);
            if (!is_array($doc) || empty($doc['staff']) || !is_array($doc['staff'])) continue;
            foreach (explode(',', KOP_PEOPLE_LISTS) as $list) {
                foreach ((array) ($doc['staff'][$list] ?? array()) as $e) {
                    if (!is_array($e) || empty($e['personId']) || trim((string) ($e['pastJobs'] ?? '')) === '') continue;
                    $pid = (int) $e['personId'];
                    foreach (kop_pjobs_parse($e['pastJobs']) as $job) {
                        $k = kop_pjobs_key($pid, $job);
                        if (isset($seen[$k])) continue;
                        $seen[$k] = true;
                        $add[] = array($pid, $job['place'], $job['role'], $job['years'], mb_substr((string) ($e['source'] ?? ''), 0, 255),
                            mb_substr((string) ($e['sourceUrl'] ?? ''), 0, 500), 'pastjobs', $k);
                    }
                }
            }
        }
        foreach (array_chunk($add, 200) as $chunk) {
            $sql = "INSERT INTO {$t} (person_id, place, role, years, source, source_url, origin, import_key) VALUES "
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)'));
            $params = array();
            foreach ($chunk as $r) foreach ($r as $v) $params[] = $v;
            kop_facility_db_exec($sql, $params, $opts);
        }
        if ($add) {
            $GLOBALS['kop_pjobs_cache'] = null;
            $GLOBALS['kop_pjobs_keys'] = null;
            if (function_exists('kop_people_version_bump') && empty($opts['dry_run'])) kop_people_version_bump();
        }
        return count($add);
    }
}

if (!function_exists('kop_pjobs_by_person')) {
    /** Every shown job, [resolved person id => [{id, place, role, years, source, source_url, origin}]], cached per request. */
    function kop_pjobs_by_person(array $opts = array()) {
        $cache = empty($opts);
        if ($cache && isset($GLOBALS['kop_pjobs_cache']) && is_array($GLOBALS['kop_pjobs_cache'])) return $GLOBALS['kop_pjobs_cache'];
        $opts = $opts ?: (array) ($GLOBALS['kop_pjobs_opts'] ?? array());
        $out = array();
        if (kop_pjobs_ready($opts)) {
            $state = function_exists('kop_people_load') ? kop_people_load($opts) : null;
            foreach (kop_facility_db_rows('SELECT id, person_id, place, role, years, source, source_url, origin FROM ' . kop_pjobs_table($opts)
                . ' WHERE hidden = 0 ORDER BY id', array(), $opts) as $r) {
                $pid = $state ? kop_people_resolve($state, (int) $r['person_id']) : (int) $r['person_id'];
                $out[$pid][] = $r;
            }
        }
        if ($cache) $GLOBALS['kop_pjobs_cache'] = $out;
        return $out;
    }

    /** One person's jobs (their id resolved through merges), or null when the table is not there. */
    function kop_pjobs_for_person($person_id, array $opts = array()) {
        $opts = $opts ?: (array) ($GLOBALS['kop_pjobs_opts'] ?? array());
        if (!kop_pjobs_ready($opts)) return null;
        $pid = (int) $person_id;
        if (function_exists('kop_people_load')) $pid = kop_people_resolve(kop_people_load($opts), $pid);
        return kop_pjobs_by_person($opts)[$pid] ?? array();
    }
}

if (!function_exists('kop_pjobs_save')) {
    /**
     * Add ($id 0), change or take off ($fields['hidden'] = 1) one job, logged for Undo.
     * $fields: place, role, years, source, source_url. Returns the job id.
     */
    function kop_pjobs_save($person_id, $id, array $fields, array $opts = array()) {
        $t = kop_pjobs_table($opts);
        $clean = array();
        foreach (array('place' => 255, 'role' => 255, 'years' => 64, 'source' => 255, 'source_url' => 500) as $k => $max) {
            if (array_key_exists($k, $fields)) $clean[$k] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $fields[$k])), 0, $max);
        }
        if (array_key_exists('hidden', $fields)) $clean['hidden'] = $fields['hidden'] ? 1 : 0;
        if (isset($clean['source_url']) && $clean['source_url'] !== '' && !preg_match('#^https?://#i', $clean['source_url'])) throw new RuntimeException('The source link must start with http:// or https://.');
        $before = null;
        if ($id) {
            $rows = kop_facility_db_rows("SELECT * FROM {$t} WHERE id = ?", array((int) $id), $opts);
            if (!$rows) throw new RuntimeException('That job is gone.');
            $before = $rows[0];
            if ((int) $before['person_id'] !== (int) $person_id && function_exists('kop_people_load')
                && kop_people_resolve(kop_people_load($opts), (int) $before['person_id']) !== (int) $person_id) throw new RuntimeException('That job is someone else\'s.');
            if (!$clean) return (int) $id;
            if (array_key_exists('place', $clean) && $clean['place'] === '') throw new RuntimeException('A job needs a place (a program, company or anything else).');
            $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($clean)));
            kop_facility_db_exec("UPDATE {$t} SET {$set} WHERE id = ?", array_merge(array_values($clean), array((int) $id)), $opts);
        } else {
            if (($clean['place'] ?? '') === '') throw new RuntimeException('A job needs a place (a program, company or anything else).');
            $clean += array('role' => '', 'years' => '', 'source' => '', 'source_url' => '');
            kop_facility_db_exec("INSERT INTO {$t} (person_id, place, role, years, source, source_url, origin) VALUES (?, ?, ?, ?, ?, ?, 'manual')",
                array((int) $person_id, $clean['place'], $clean['role'], $clean['years'], $clean['source'], $clean['source_url']), $opts);
            $new = kop_facility_db_rows("SELECT MAX(id) AS id FROM {$t} WHERE person_id = ?", array((int) $person_id), $opts);
            $id = (int) ($new[0]['id'] ?? 0);
        }
        $after = kop_facility_db_rows("SELECT * FROM {$t} WHERE id = ?", array((int) $id), $opts)[0] ?? null;
        kop_pjobs_log(array('id' => (int) $id, 'person' => (int) $person_id, 'before' => $before, 'after' => $after));
        $GLOBALS['kop_pjobs_cache'] = null;
        if (function_exists('kop_people_version_bump')) kop_people_version_bump();
        return (int) $id;
    }

    function kop_pjobs_log(array $entry) {
        $log = function_exists('get_option') ? (array) get_option('kop_person_jobs_log', array()) : (array) ($GLOBALS['kop_person_jobs_log'] ?? array());
        $entry += array('at' => gmdate('Y-m-d H:i:s'), 'by' => function_exists('wp_get_current_user') ? (string) wp_get_current_user()->user_login : '', 'undone' => false);
        $entry['n'] = (int) (end($log)['n'] ?? 0) + 1;
        $log[] = $entry;
        $log = array_slice($log, -500);
        if (function_exists('update_option')) update_option('kop_person_jobs_log', $log, false);
        else $GLOBALS['kop_person_jobs_log'] = $log;
    }

    /** One person's recent changes, newest first. */
    function kop_pjobs_log_for($person_id) {
        $log = function_exists('get_option') ? (array) get_option('kop_person_jobs_log', array()) : (array) ($GLOBALS['kop_person_jobs_log'] ?? array());
        return array_reverse(array_values(array_filter($log, function ($l) use ($person_id) { return (int) $l['person'] === (int) $person_id; })));
    }

    /**
     * Undo change $n: the job back as it was before it (a new job deleted), while nobody changed it since.
     */
    function kop_pjobs_undo($n, array $opts = array()) {
        $key = function_exists('get_option') ? 'opt' : 'glob';
        $log = $key === 'opt' ? (array) get_option('kop_person_jobs_log', array()) : (array) ($GLOBALS['kop_person_jobs_log'] ?? array());
        $at = null;
        foreach ($log as $i => $l) if ((int) $l['n'] === (int) $n) $at = $i;
        if ($at === null || !empty($log[$at]['undone'])) throw new RuntimeException('Nothing to undo there.');
        $l = $log[$at];
        $t = kop_pjobs_table($opts);
        $now = kop_facility_db_rows("SELECT * FROM {$t} WHERE id = ?", array((int) $l['id']), $opts)[0] ?? null;
        $cols = array('place', 'role', 'years', 'source', 'source_url', 'hidden');
        $same = function ($a, $b) use ($cols) {
            if (!$a || !$b) return !$a && !$b;
            foreach ($cols as $c) if ((string) $a[$c] !== (string) $b[$c]) return false;
            return true;
        };
        if (!$same($now, $l['after'])) throw new RuntimeException('That job changed since; undo the later change first.');
        if ($l['before'] === null) {
            kop_facility_db_exec("DELETE FROM {$t} WHERE id = ?", array((int) $l['id']), $opts);
        } else {
            $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, $cols));
            kop_facility_db_exec("UPDATE {$t} SET {$set} WHERE id = ?", array_merge(array_map(function ($c) use ($l) { return (string) $l['before'][$c]; }, $cols), array((int) $l['id'])), $opts);
        }
        $log[$at]['undone'] = true;
        if ($key === 'opt') update_option('kop_person_jobs_log', $log, false);
        else $GLOBALS['kop_person_jobs_log'] = $log;
        $GLOBALS['kop_pjobs_cache'] = null;
        if (function_exists('kop_people_version_bump')) kop_people_version_bump();
    }
}
