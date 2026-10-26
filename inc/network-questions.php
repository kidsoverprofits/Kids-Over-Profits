<?php
/**
 * Network map questions, answered in wp-admin (KOP Tools > Map Questions).
 *
 * The map build (kop-pipeline scripts/build-network-graph.js) has to guess at
 * some rows: what a name is (a program, a company, a trade group), who bought
 * whom when the board's wording works both ways, which KOP record a name is
 * when two share it, which of two closed names a rename ran from, which map
 * name a memorial program is. Those used to go to tmp/network-qa.md, a file
 * nobody can open from here (owner, 2026-10-25). The build now writes the
 * decisions among them beside graph.json as js/data/network/network-qa.json
 * (kop-data), each a question in plain words with its evidence and the answers
 * it takes. This screen puts them on cards, a button per answer.
 *
 * With them sit six curated aliases that may name another era of their
 * record (PLAN-ARCHIVE.md "Owner pass on curated aliases"): "Is X a past name
 * of Y, or its own program?".
 *
 * An answer is a row of {prefix}kop_network_answers. The hourly rebuild
 * (inc/network-rebuild.php) sees the table change and starts a build; the
 * build reads the rows as overrides (readAnswers there), so a build question
 * is settled on the map within the hour and leaves this screen. An alias
 * answer changes the record at once: "a past name" adds it to the record's
 * past names, "its own program" takes it off the record's match aliases (so
 * the name no longer folds into that record) and the plan lists it for a
 * record of its own. Undo deletes the row and puts the record back as it was.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_NQ_DB_VERSION = '1';

if (!function_exists('kop_network_questions_table')) {
    function kop_network_questions_table() {
        global $wpdb;
        return $wpdb->prefix . 'kop_network_answers';
    }
}

if (!function_exists('kop_network_questions_install')) {
    function kop_network_questions_install($force = false) {
        if (!$force && get_option('kop_nq_db') === KOP_NQ_DB_VERSION) return;
        global $wpdb;
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_network_questions_table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_id VARCHAR(190) NOT NULL,
            section VARCHAR(40) NOT NULL DEFAULT '',
            subject LONGTEXT NULL,
            answer VARCHAR(190) NOT NULL DEFAULT '',
            answer_label VARCHAR(255) NOT NULL DEFAULT '',
            answered_by VARCHAR(60) NOT NULL DEFAULT '',
            answered_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY question_id (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        update_option('kop_nq_db', KOP_NQ_DB_VERSION, false);
    }
    add_action('admin_init', 'kop_network_questions_install', 5);
}

/* ---- The questions ---------------------------------------------------- */

if (!function_exists('kop_network_questions_sections')) {
    /** section => [tab label, one line on what answering does]. */
    function kop_network_questions_sections() {
        return array(
            'alias'        => array('Past name or own program', 'A past name goes onto the record; its own program comes off the record\'s match aliases and waits for a record of its own.'),
            'kind'         => array('What is it', 'The map draws the name as what you say (the word under the name, the filters) from the next hourly rebuild.'),
            'company'      => array('Company or program', 'Names drawn as programs that own other names on the board.'),
            'acquirer'     => array('Who bought whom', 'Sets which way the arrowhead points on the line.'),
            'record'       => array('Which record', 'Two or more KOP records share the name; the map links the one you pick.'),
            'suggestion'   => array('Same place?', 'A record with a similar name; the map links it if you say it is the same place.'),
            'relationship' => array('Kind of connection', 'Board wording the build could not place; drawn as "unrecorded" until it is.'),
            'rebrand'      => array('Which name came first', 'Both names are closed, so nothing says which way the rename ran.'),
            'deaths'       => array('Memorial deaths', 'Which map name a memorial program is, so its deaths show there.'),
        );
    }
}

if (!function_exists('kop_network_questions_file')) {
    function kop_network_questions_file() {
        return function_exists('kop_network_map_file') ? kop_network_map_file('network-qa.json')
            : trailingslashit(get_stylesheet_directory()) . 'js/data/network/network-qa.json';
    }
}

if (!function_exists('kop_network_questions_build')) {
    /** The build's questions, id => question; empty when the file is missing. */
    function kop_network_questions_build() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = array();
        $path = kop_network_questions_file();
        $data = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        foreach ((array) ($data['questions'] ?? array()) as $q) {
            if (empty($q['id']) || empty($q['options']) || !is_array($q['options'])) continue;
            $memo[(string) $q['id']] = array(
                'id'       => (string) $q['id'],
                'section'  => (string) ($q['section'] ?? 'kind'),
                'name'     => (string) ($q['name'] ?? ''),
                'question' => (string) ($q['question'] ?? ''),
                'evidence' => array_values(array_map('strval', (array) ($q['evidence'] ?? array()))),
                'options'  => array_values(array_map(function ($o) {
                    return array('value' => (string) ($o['value'] ?? ''), 'label' => (string) ($o['label'] ?? $o['value'] ?? ''));
                }, $q['options'])),
                'subject'  => is_array($q['subject'] ?? null) ? $q['subject'] : array(),
                'node'     => (string) ($q['node'] ?? ''),
                'links'    => array(),
            );
        }
        return $memo;
    }
}

if (!function_exists('kop_network_questions_alias_list')) {
    /**
     * The six curated aliases (api/match-aliases-seed.json) that may name
     * another era of their record. 'record' is the id the seed named; 'now'
     * the record it lives on today where that one was merged away.
     */
    function kop_network_questions_alias_list() {
        return array(
            array('alias' => 'Bethel Boys Academy', 'record' => 13927, 'now' => 100180),
            array('alias' => 'Laurel Ridge Treatment Center', 'record' => 10602, 'now' => 10815),
            array('alias' => 'Agape Boarding School', 'record' => 11361),
            array('alias' => 'Refuge of Grace', 'record' => 11462),
            array('alias' => 'Change Academy at Lake of the Ozarks', 'record' => 11355),
            array('alias' => 'Cleo Wallace Academy', 'record' => 12621),
        );
    }
}

if (!function_exists('kop_network_questions_record_opts')) {
    /** kop_facility_load()/save() options for facilities_v2, or null when the records database is not reachable. */
    function kop_network_questions_record_opts() {
        static $opts = false;
        if ($opts !== false) return $opts;
        $opts = null;
        if (!function_exists('kop_seed_pdo') || !function_exists('kop_facility_load')) return $opts;
        try {
            $pdo = kop_seed_pdo();
            if (!$pdo) return $opts;
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            global $wpdb;
            $opts = array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
        } catch (Throwable $e) {
            $opts = null;
        }
        return $opts;
    }
}

if (!function_exists('kop_network_questions_record')) {
    /** The record an alias question is about: ['id', 'name', 'past', 'other', 'match', 'status', 'years', 'doc'] or null. */
    function kop_network_questions_record($id) {
        $opts = kop_network_questions_record_opts();
        if (!$opts) return null;
        try {
            $stored = kop_facility_load((int) $id, $opts);
        } catch (Throwable $e) {
            return null;
        }
        if (!$stored) return null;
        $doc = $stored['doc'];
        $f = isset($doc['facility']) && is_array($doc['facility']) ? $doc['facility'] : $doc;
        $ident = (array) ($f['identification'] ?? array());
        $list = function ($v) {
            return array_values(array_filter(array_map(function ($x) {
                return is_string($x) ? trim($x) : (is_array($x) && isset($x['name']) ? trim((string) $x['name']) : '');
            }, (array) $v)));
        };
        $period = (array) ($f['operatingPeriod'] ?? array());
        return array(
            'id'     => (int) $stored['id'],
            'name'   => (string) ($ident['name'] ?? $stored['unique_name']),
            'past'   => $list($ident['pastNames'] ?? array()),
            'other'  => $list($ident['otherNames'] ?? array()),
            'match'  => $list($doc['legacy']['matchAliases'] ?? array()),
            'status' => (string) ($ident['status'] ?? ($f['status'] ?? '')),
            'years'  => trim(((string) ($period['startYear'] ?? '')) . ((($period['startYear'] ?? '') !== '' || ($period['endYear'] ?? '') !== '') ? ' to ' : '') . ((string) ($period['endYear'] ?? ''))),
            'stored' => $stored,
        );
    }
}

if (!function_exists('kop_network_questions_aliases')) {
    /** The alias questions, id => question, in the same shape as the build's. */
    function kop_network_questions_aliases() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = array();
        foreach (kop_network_questions_alias_list() as $a) {
            $id = 'alias:' . sanitize_title($a['alias']) . '--' . (int) $a['record'];
            $rid = (int) ($a['now'] ?? $a['record']);
            $rec = kop_network_questions_record($rid);
            $evidence = array();
            $links = array();
            if ($rec) {
                $evidence[] = 'Record #' . $rec['id'] . ': ' . $rec['name'] . ($rec['status'] !== '' ? ' (' . $rec['status'] . ')' : '') . ($rec['years'] !== '' ? ', ' . $rec['years'] : '');
                if (!empty($a['now']) && (int) $a['now'] !== (int) $a['record']) $evidence[] = 'The alias was filed on #' . (int) $a['record'] . ', since merged into this record.';
                $evidence[] = 'Past names on the record: ' . ($rec['past'] ? implode('; ', $rec['past']) : 'none');
                $evidence[] = 'Other names on the record: ' . ($rec['other'] ? implode('; ', $rec['other']) : 'none');
                $evidence[] = 'Match aliases (names that fold into this record when matching): ' . ($rec['match'] ? implode('; ', $rec['match']) : 'none');
                $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($rec['id']) : '';
                if ($url !== '') $links[] = array('label' => 'Open the facility page', 'url' => $url);
            } else {
                $evidence[] = 'Record #' . $rid . ' could not be read from facilities_v2 (merged away, or the records database is not reachable).';
            }
            $memo[$id] = array(
                'id'       => $id,
                'section'  => 'alias',
                'name'     => $a['alias'],
                'question' => 'Is "' . $a['alias'] . '" a past name of ' . ($rec ? $rec['name'] : 'record #' . $rid) . ', or its own program?',
                'evidence' => $evidence,
                'options'  => array(
                    array('value' => 'past-name', 'label' => 'A past name of this record'),
                    array('value' => 'own-program', 'label' => 'Its own program (needs a record of its own)'),
                ),
                'subject'  => array('alias' => $a['alias'], 'record' => $rid),
                'node'     => '',
                'links'    => $links,
            );
        }
        return $memo;
    }
}

if (!function_exists('kop_network_questions_all')) {
    /** Every question, alias ones first, id => question. */
    function kop_network_questions_all() {
        return kop_network_questions_aliases() + kop_network_questions_build();
    }
}

/* ---- The answers ------------------------------------------------------ */

if (!function_exists('kop_network_questions_answers')) {
    /** question_id => row (section, subject (decoded), answer, answer_label, answered_by, answered_at). */
    function kop_network_questions_answers() {
        global $wpdb;
        $table = kop_network_questions_table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return array();
        $out = array();
        foreach ((array) $wpdb->get_results('SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A) as $row) {
            $row['subject'] = json_decode((string) $row['subject'], true) ?: array();
            $out[(string) $row['question_id']] = $row;
        }
        return $out;
    }
}

if (!function_exists('kop_network_questions_put')) {
    /** Store (or replace) an answer row. */
    function kop_network_questions_put(array $row) {
        global $wpdb;
        $table = kop_network_questions_table();
        $wpdb->delete($table, array('question_id' => $row['question_id']));
        $wpdb->insert($table, array(
            'question_id'  => $row['question_id'],
            'section'      => $row['section'],
            'subject'      => wp_json_encode($row['subject'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'answer'       => $row['answer'],
            'answer_label' => $row['answer_label'],
            'answered_by'  => $row['answered_by'],
            'answered_at'  => $row['answered_at'],
        ));
    }
}

if (!function_exists('kop_network_questions_remove')) {
    function kop_network_questions_remove($question_id) {
        global $wpdb;
        $wpdb->delete(kop_network_questions_table(), array('question_id' => $question_id));
    }
}

if (!function_exists('kop_network_questions_open')) {
    /** Questions with no answer yet, id => question. */
    function kop_network_questions_open() {
        $answers = kop_network_questions_answers();
        return array_diff_key(kop_network_questions_all(), $answers);
    }
}

/* ---- Alias answers change the record --------------------------------- */

if (!function_exists('kop_network_questions_alias_apply')) {
    /**
     * 'past-name': the alias joins the record's past names. 'own-program': it
     * leaves the record's match aliases. Returns what was there before (for
     * Undo) or throws with a plain reason. Nothing to change is not an error.
     */
    function kop_network_questions_alias_apply(array $subject, $answer) {
        $alias = (string) ($subject['alias'] ?? '');
        $rid = (int) ($subject['record'] ?? 0);
        $before = array('changed' => false);
        if ($alias === '' || $rid <= 0) return $before;
        $opts = kop_network_questions_record_opts();
        if (!$opts) throw new RuntimeException('The records database is not reachable, so the record was not changed.');
        require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
        if (!kop_v2_writes_active($opts['pdo'], $opts['prefix'])) throw new RuntimeException('Facility saves are not on facilities_v2.');
        $same = function ($a, $b) { return strcasecmp(trim((string) $a), trim((string) $b)) === 0; };
        kop_v2_with_write_lock($opts['pdo'], function () use ($rid, $alias, $answer, $opts, $same, &$before) {
            $stored = kop_facility_load($rid, $opts);
            if (!$stored) throw new RuntimeException('Record #' . $rid . ' does not exist.');
            $doc = $stored['doc'];
            if ($answer === 'past-name') {
                $past = array_values((array) ($doc['identification']['pastNames'] ?? array()));
                foreach ($past as $p) if ($same(is_array($p) ? ($p['name'] ?? '') : $p, $alias)) return;
                $before = array('changed' => true, 'field' => 'pastNames', 'list' => $past);
                $past[] = $alias;
                $doc['identification']['pastNames'] = $past;
            } elseif ($answer === 'own-program') {
                $match = array_values((array) ($doc['legacy']['matchAliases'] ?? array()));
                $kept = array_values(array_filter($match, function ($m) use ($same, $alias) { return !$same($m, $alias); }));
                if (count($kept) === count($match)) return;
                $before = array('changed' => true, 'field' => 'matchAliases', 'list' => $match);
                $doc['legacy']['matchAliases'] = $kept;
            } else {
                return;
            }
            kop_network_questions_record_write($stored, $doc, $opts);
        });
        return $before;
    }
}

if (!function_exists('kop_network_questions_alias_restore')) {
    /** Undo: the list goes back to what it was. */
    function kop_network_questions_alias_restore(array $subject) {
        $before = (array) ($subject['before'] ?? array());
        $rid = (int) ($subject['record'] ?? 0);
        if (empty($before['changed']) || $rid <= 0) return;
        $opts = kop_network_questions_record_opts();
        if (!$opts) throw new RuntimeException('The records database is not reachable, so the record was not put back.');
        require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
        kop_v2_with_write_lock($opts['pdo'], function () use ($rid, $before, $opts) {
            $stored = kop_facility_load($rid, $opts);
            if (!$stored) return;
            $doc = $stored['doc'];
            if ($before['field'] === 'pastNames') $doc['identification']['pastNames'] = array_values((array) $before['list']);
            else $doc['legacy']['matchAliases'] = array_values((array) $before['list']);
            kop_network_questions_record_write($stored, $doc, $opts);
        });
    }
}

if (!function_exists('kop_network_questions_record_write')) {
    /** Through the legacy shape and back, as the inline editor saves, so derived fields come out current. */
    function kop_network_questions_record_write(array $stored, array $doc, array $opts) {
        $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
            'facility_id' => $doc['facility_id'] ?? $stored['id'],
            'unique_name' => $doc['provenance']['uniqueName'] ?? $stored['unique_name'],
        ));
        $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
        try {
            kop_facility_save($out, $opts);
        } catch (RuntimeException $e) {
            throw new RuntimeException(preg_replace('/^kop_facility_save: /', 'Not saved, the record does not check out: ', $e->getMessage()));
        }
    }
}

/* ---- Saving an answer ------------------------------------------------- */

if (!function_exists('kop_network_questions_decide')) {
    /**
     * $items: [{id, answer}] where answer is one of the question's option
     * values, 'skip' (leave it as the build guessed), or 'undo'. Returns
     * id => {answer, label} as stored, or {error}.
     */
    function kop_network_questions_decide(array $items, $login) {
        $all = kop_network_questions_all();
        $answers = kop_network_questions_answers();
        $out = array();
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $answer = (string) ($item['answer'] ?? '');
            if ($id === '') continue;
            if ($answer === 'undo') {
                $row = $answers[$id] ?? null;
                if (!$row) { $out[$id] = array('answer' => '', 'label' => ''); continue; }
                if ($row['section'] === 'alias') {
                    try {
                        kop_network_questions_alias_restore((array) $row['subject']);
                    } catch (Throwable $e) {
                        $out[$id] = array('error' => $e->getMessage());
                        continue;
                    }
                }
                kop_network_questions_remove($id);
                unset($answers[$id]);
                $out[$id] = array('answer' => '', 'label' => '');
                continue;
            }
            $q = $all[$id] ?? null;
            if (!$q) { $out[$id] = array('error' => 'That question is not on the list any more.'); continue; }
            $label = '';
            foreach ($q['options'] as $o) if ($o['value'] === $answer) $label = $o['label'];
            if ($answer === 'skip') $label = 'Left as the build guessed';
            if ($label === '') { $out[$id] = array('error' => 'That is not one of the answers.'); continue; }
            $subject = $q['subject'];
            if ($q['section'] === 'alias' && $answer !== 'skip') {
                try {
                    $subject['before'] = kop_network_questions_alias_apply($subject, $answer);
                } catch (Throwable $e) {
                    $out[$id] = array('error' => $e->getMessage());
                    continue;
                }
            }
            $row = array(
                'question_id' => $id, 'section' => $q['section'], 'subject' => $subject,
                'answer' => $answer, 'answer_label' => $label,
                'answered_by' => (string) $login, 'answered_at' => gmdate('Y-m-d H:i:s'),
            );
            kop_network_questions_put($row);
            $answers[$id] = $row;
            $out[$id] = array('answer' => $answer, 'label' => $label);
        }
        return $out;
    }
}

if (!function_exists('kop_network_questions_ajax')) {
    function kop_network_questions_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Only an administrator can answer map questions.'), 403);
        }
        check_ajax_referer('kop_network_questions', 'nonce');
        $items = json_decode(wp_unslash((string) ($_POST['items'] ?? '[]')), true);
        if (!is_array($items) || !$items) {
            wp_send_json_error(array('message' => 'Nothing to save.'), 400);
        }
        $user = wp_get_current_user();
        wp_send_json_success(array('saved' => kop_network_questions_decide($items, $user ? $user->user_login : '')));
    }
    add_action('wp_ajax_kop_network_questions_answer', 'kop_network_questions_ajax');
}

/* ---- The review screen ------------------------------------------------ */

if (!function_exists('kop_network_questions_menu')) {
    function kop_network_questions_menu() {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Map Questions', 'Map Questions', 'manage_options',
            'kop-network-questions', 'kop_network_questions_page');
    }
    add_action('admin_menu', 'kop_network_questions_menu', 22);
}

if (!function_exists('kop_network_questions_rows')) {
    /** Every question as the screen shows it, with its answer where there is one. */
    function kop_network_questions_rows() {
        $answers = kop_network_questions_answers();
        $map = function_exists('kop_network_map_page_url') ? kop_network_map_page_url() : home_url('/network-map/');
        $rows = array();
        foreach (kop_network_questions_all() as $id => $q) {
            $a = $answers[$id] ?? null;
            $links = $q['links'];
            if ($q['node'] !== '') $links[] = array('label' => 'See it on the map', 'url' => $map . '#open=' . rawurlencode($q['node']));
            $rows[] = array(
                'id' => $id, 'section' => $q['section'], 'name' => $q['name'], 'question' => $q['question'],
                'evidence' => $q['evidence'], 'options' => $q['options'], 'links' => $links,
                'answer' => $a ? (string) $a['answer'] : '', 'label' => $a ? (string) $a['answer_label'] : '',
                'by' => $a ? (string) $a['answered_by'] : '', 'at' => $a ? (string) $a['answered_at'] : '',
            );
        }
        // Answered rows whose question has left the list (settled by a rebuild) still show under Answered, for Undo.
        foreach ($answers as $id => $a) {
            if (isset(kop_network_questions_all()[$id])) continue;
            $s = (array) $a['subject'];
            $rows[] = array(
                'id' => $id, 'section' => (string) $a['section'], 'name' => (string) ($s['name'] ?? $s['alias'] ?? $s['raw'] ?? $s['program'] ?? (($s['from'] ?? '') . ' / ' . ($s['to'] ?? ''))),
                'question' => 'Settled on the map.', 'evidence' => array(), 'options' => array(), 'links' => array(),
                'answer' => (string) $a['answer'], 'label' => (string) $a['answer_label'], 'by' => (string) $a['answered_by'], 'at' => (string) $a['answered_at'],
            );
        }
        return $rows;
    }
}

if (!function_exists('kop_network_questions_page')) {
    function kop_network_questions_page() {
        if (!current_user_can('manage_options')) return;
        $rows = kop_network_questions_rows();
        $sections = kop_network_questions_sections();
        $rebuild = admin_url('admin.php?page=kop-network-rebuild');
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kop_network_questions'),
            'rows' => $rows,
            'sections' => $sections,
        );
        ?>
        <div class="wrap kop-nq">
            <h1>Map questions</h1>
            <p class="kop-nq__intro">
                Decisions the network map build cannot make on its own, one card each: what a name is, who bought whom,
                which record a name is, which of two closed names came first, and six curated aliases that may be another
                era of their record. Read the evidence, press the answer. A build answer reaches the map at the next hourly
                <a href="<?php echo esc_url($rebuild); ?>">rebuild</a>; an alias answer changes the record at once. Everything
                saves as you click, and Undo is on the Answered tab.
            </p>
            <?php if (!$rows) : ?>
                <div class="notice notice-info"><p>No questions yet: the next map build writes them.</p></div>
            <?php else : ?>
            <div class="kop-nq__bar">
                <div class="kop-nq__tabs" role="tablist"></div>
                <span class="kop-nq__status" role="status" aria-live="polite"></span>
            </div>
            <p class="kop-nq__help"></p>
            <div class="kop-nq__list"></div>
            <?php endif; ?>
        </div>
        <style>
            .kop-nq__intro { max-width: 60em; font-size: 14px; }
            .kop-nq__bar { position: sticky; top: 32px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
                margin: 16px 0 8px; padding: 10px 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; }
            .kop-nq__tabs { display: flex; flex-wrap: wrap; gap: 4px; }
            .kop-nq__tabs button { padding: 6px 12px; border: 1px solid #c3c4c7; border-radius: 999px; background: #f6f7f7; cursor: pointer; font-size: 13px; }
            .kop-nq__tabs button[aria-selected="true"] { background: #000080; border-color: #000080; color: #fff; }
            .kop-nq__tabs span { font-weight: 600; }
            .kop-nq__status { color: #1d7a33; font-weight: 600; }
            .kop-nq__help { max-width: 60em; color: #50575e; margin: 0 0 12px; }
            .kop-nq__list { display: grid; gap: 12px; max-width: 1100px; }
            .kop-nq__card { padding: 14px 16px; background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #33a7b5; border-radius: 6px; }
            .kop-nq__card.is-answered { border-left-color: #1d7a33; }
            .kop-nq__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px 14px; }
            .kop-nq__q { margin: 0; font-size: 16px; }
            .kop-nq__badge { padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #f0f0f1; }
            .kop-nq__evidence { margin: 8px 0; padding: 0; list-style: none; }
            .kop-nq__evidence li { margin: 4px 0; padding: 6px 10px; background: #f6f7f7; border-radius: 4px; }
            .kop-nq__links { display: flex; flex-wrap: wrap; gap: 12px; margin: 4px 0; }
            .kop-nq__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; }
            .kop-nq__done { font-weight: 600; }
            .kop-nq__error { color: #b32d2e; font-weight: 600; }
            .kop-nq__empty { padding: 24px; background: #fff; border: 1px dashed #c3c4c7; border-radius: 6px; text-align: center; }
        </style>
        <script>
        (function () {
            var C = <?php echo wp_json_encode($config); ?>;
            var list = document.querySelector('.kop-nq__list');
            if (!list) return;
            var tabs = document.querySelector('.kop-nq__tabs');
            var help = document.querySelector('.kop-nq__help');
            var status = document.querySelector('.kop-nq__status');
            var rows = C.rows;
            var order = Object.keys(C.sections).concat(['answered']);
            var tab = '';

            function el(tag, cls, text) {
                var n = document.createElement(tag);
                if (cls) n.className = cls;
                if (text !== undefined) n.textContent = text;
                return n;
            }
            function tabOf(r) { return r.answer ? 'answered' : r.section; }
            function labelOf(section) { return section === 'answered' ? 'Answered' : (C.sections[section] ? C.sections[section][0] : section); }

            function save(items) {
                var body = new FormData();
                body.append('action', 'kop_network_questions_answer');
                body.append('nonce', C.nonce);
                body.append('items', JSON.stringify(items));
                status.textContent = 'Saving...';
                fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Not saved.');
                        var saved = json.data.saved || {};
                        var errors = 0;
                        rows.forEach(function (r) {
                            var s = saved[r.id];
                            if (!s) return;
                            if (s.error) { r.error = s.error; errors++; return; }
                            r.error = '';
                            r.answer = s.answer;
                            r.label = s.label;
                        });
                        status.textContent = errors ? errors + ' not saved; see the red note.' : 'Saved.';
                        render();
                    })
                    .catch(function (e) { status.textContent = 'Not saved: ' + e.message; });
            }

            function card(r) {
                var c = el('article', 'kop-nq__card' + (r.answer ? ' is-answered' : ''));
                var head = el('div', 'kop-nq__head');
                head.appendChild(el('h2', 'kop-nq__q', r.question));
                head.appendChild(el('span', 'kop-nq__badge', labelOf(r.section)));
                c.appendChild(head);
                if (r.evidence.length) {
                    var ul = el('ul', 'kop-nq__evidence');
                    r.evidence.forEach(function (e) { ul.appendChild(el('li', '', e)); });
                    c.appendChild(ul);
                }
                if (r.links.length) {
                    var links = el('div', 'kop-nq__links');
                    r.links.forEach(function (l) {
                        var a = el('a', '', l.label);
                        a.href = l.url; a.target = '_blank'; a.rel = 'noopener';
                        links.appendChild(a);
                    });
                    c.appendChild(links);
                }
                if (r.error) c.appendChild(el('p', 'kop-nq__error', r.error));
                var actions = el('div', 'kop-nq__actions');
                if (r.answer) {
                    actions.appendChild(el('span', 'kop-nq__done', 'Answered: ' + r.label + (r.by ? ' (' + r.by + ')' : '') +
                        (r.section === 'alias' ? '.' : '. On the map after the next rebuild.')));
                    var undo = el('button', 'button', 'Undo');
                    undo.type = 'button';
                    undo.addEventListener('click', function () { save([{ id: r.id, answer: 'undo' }]); });
                    actions.appendChild(undo);
                } else {
                    r.options.forEach(function (o, i) {
                        var b = el('button', 'button' + (i === 0 ? ' button-primary' : ''), o.label);
                        b.type = 'button';
                        b.addEventListener('click', function () { save([{ id: r.id, answer: o.value }]); });
                        actions.appendChild(b);
                    });
                    var skip = el('button', 'button-link', r.section === 'alias' ? 'Not sure, leave it' : 'Leave it as the build guessed');
                    skip.type = 'button';
                    skip.addEventListener('click', function () { save([{ id: r.id, answer: 'skip' }]); });
                    actions.appendChild(skip);
                }
                c.appendChild(actions);
                return c;
            }

            function render() {
                var counts = {};
                rows.forEach(function (r) { var t = tabOf(r); counts[t] = (counts[t] || 0) + 1; });
                var shown = order.filter(function (s) { return s === 'answered' || counts[s]; });
                if (shown.indexOf(tab) === -1) tab = shown.filter(function (s) { return s !== 'answered'; })[0] || 'answered';
                tabs.textContent = '';
                shown.forEach(function (s) {
                    var b = el('button', '', labelOf(s) + ' ');
                    b.type = 'button'; b.setAttribute('role', 'tab'); b.setAttribute('data-tab', s);
                    b.setAttribute('aria-selected', s === tab ? 'true' : 'false');
                    b.appendChild(el('span', '', '(' + (counts[s] || 0) + ')'));
                    b.addEventListener('click', function () { tab = s; status.textContent = ''; render(); });
                    tabs.appendChild(b);
                });
                help.textContent = tab === 'answered' ? 'Undo takes an answer back; a build answer then returns to its tab after the next rebuild.' : (C.sections[tab] ? C.sections[tab][1] : '');
                list.textContent = '';
                var here = rows.filter(function (r) { return tabOf(r) === tab; });
                if (!here.length) list.appendChild(el('p', 'kop-nq__empty', tab === 'answered' ? 'Nothing answered yet.' : 'Nothing left here.'));
                here.forEach(function (r) { list.appendChild(card(r)); });
            }
            render();
        })();
        </script>
        <?php
    }
}
