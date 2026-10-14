<?php
/**
 * Rules and practices from programs' own papers (docs/PLAN.md 3.21).
 *
 * The media library holds programs' handbooks, parent manuals, ground rules,
 * phase sheets and enrollment papers, filed under each program, and for years
 * nothing read them: wiki entries said nothing was known about a program's
 * rules while its student manual sat on its page. kop-pipeline reads them
 * (scripts/doc-text.py: every document's text page by page; readers per
 * scripts/doc-read-instructions.md, every quote checked against its page) and
 * exports one line per rule, level, punishment, limit on contact with family,
 * restraint or search policy:
 *
 *   ~/kop-import/doc-read/practices.json
 *     {built, items: [{key, facility_id, doc_id, doc_title, doc_url, page, topic, value, quote, year, checklist: [...]}]}
 *
 * The owner reviews them at Review inbox > Rules from documents
 * (inc/review-inbox/program-practices.php): one card per program and topic,
 * every line ticked, each linked to its page of our copy. Add puts the ticked
 * lines on the facility page's "Rules and practices" section and ticks the
 * record's checklist boxes the lines show (level system, restraint, ...);
 * the unticked ones are left off. Undo puts back exactly that: the lines wait
 * again and only the boxes Add ticked come off. An admin rewords a line or
 * takes it off the page with its pencil ('pp:<id>').
 *
 * Rows: {prefix}kop_program_practices, one per line, keyed by the pipeline's
 * key (doc, page, quote) so a new export adds only new lines and never changes
 * a decided one. Decisions: option kop_pp_log, batch => {facility_id, topic,
 * added, left, ticks, by, at}.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_PP_DB_VERSION = '1';

function kop_pp_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_program_practices';
}

function kop_pp_install($force = false) {
    if (!$force && get_option('kop_pp_db') === KOP_PP_DB_VERSION) return;
    global $wpdb;
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_pp_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ikey CHAR(40) NOT NULL,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        doc_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        doc_title VARCHAR(255) NOT NULL DEFAULT '',
        doc_url VARCHAR(600) NOT NULL DEFAULT '',
        page SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        topic VARCHAR(20) NOT NULL DEFAULT '',
        value TEXT NOT NULL,
        value_orig TEXT NOT NULL,
        quote TEXT NOT NULL,
        year SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        checklist VARCHAR(600) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        batch VARCHAR(40) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ikey (ikey),
        KEY facility_topic (facility_id, topic),
        KEY status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_pp_db', KOP_PP_DB_VERSION, false);
}
add_action('admin_init', 'kop_pp_install');

function kop_pp_ready() {
    static $ready = null;
    if ($ready === null) {
        global $wpdb;
        $ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', kop_pp_table())) === kop_pp_table();
    }
    return $ready;
}

/** Topic => heading, in the order the page prints them. */
function kop_pp_topics() {
    return array(
        'levels'        => 'Levels and phases',
        'rules'         => 'Rules',
        'consequences'  => 'Punishments',
        'communication' => 'Contact with family',
        'restraint'     => 'Restraint, isolation and transport',
        'searches'      => 'Searches',
        'therapy'       => 'Therapy and seminars',
        'daily_life'    => 'Daily life',
        'education'     => 'School',
        'admission'     => 'Admission and fees',
        'family'        => 'What parents were told',
    );
}

/** The checklist boxes a line may tick (doc section => keys), as the readers were told. */
function kop_pp_checklist_keys() {
    return array(
        'ttiPractices'   => array('hasLevelSystem', 'hasRestraint', 'hasSeclusion', 'hasStripSearches', 'hasCommunicationRestrictions',
            'hasConfrontationGroups', 'hasBehaviorContracts', 'hasForcedMedication'),
        'treatmentTypes' => array('hasBehaviorModification', 'hasLGATSeminars', 'hasFeedbackHotseatGroups', 'hasWorkTherapy',
            'hasWildernessTherapy', 'hasEquineTherapy', 'hasRealityTherapy'),
        'philosophy'     => array('has12Steps', 'hasPositivePeerCulture', 'hasTherapeuticCommunity', 'hasFundamentalistBaptist', 'hasPentecostal'),
    );
}

/** "ttiPractices.hasLevelSystem" if it is one of kop_pp_checklist_keys(), else ''. */
function kop_pp_clean_check($key) {
    $parts = explode('.', (string) $key);
    if (count($parts) !== 2) return '';
    $allowed = kop_pp_checklist_keys();
    return isset($allowed[$parts[0]]) && in_array($parts[1], $allowed[$parts[0]], true) ? $parts[0] . '.' . $parts[1] : '';
}

/* ---- Import --------------------------------------------------------------- */

function kop_pp_import_file() {
    $dir = defined('KOP_DOC_READ_IMPORT_DIR') ? rtrim(KOP_DOC_READ_IMPORT_DIR, '/') : dirname(rtrim(ABSPATH, '/')) . '/kop-import/doc-read';
    return $dir . '/practices.json';
}

/**
 * Add the export's new lines (a key already in the table is left as it is,
 * decided or not). A record merged away since the export files under the kept
 * record. Runs when the file changed since the last import. Returns lines added.
 */
function kop_pp_import($force = false) {
    if (!kop_pp_ready()) return 0;
    $file = kop_pp_import_file();
    if (!is_readable($file)) return 0;
    $sig = filemtime($file) . ':' . filesize($file);
    if (!$force && get_option('kop_pp_import_seen') === $sig) return 0;
    $data = json_decode((string) file_get_contents($file), true);
    $added = 0;
    global $wpdb;
    $topics = kop_pp_topics();
    foreach ((array) ($data['items'] ?? array()) as $it) {
        if (!is_array($it)) continue;
        $key = (string) ($it['key'] ?? '');
        $value = trim((string) ($it['value'] ?? ''));
        $fid = (int) ($it['facility_id'] ?? 0);
        $topic = (string) ($it['topic'] ?? '');
        if (!preg_match('/^[0-9a-f]{40}$/', $key) || $value === '' || $fid <= 0 || !isset($topics[$topic])) continue;
        if (function_exists('kop_facility_merge_resolve')) $fid = kop_facility_merge_resolve($fid);
        $checks = array_values(array_filter(array_map('kop_pp_clean_check', (array) ($it['checklist'] ?? array()))));
        $have = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . kop_pp_table() . ' WHERE ikey = %s', $key));
        if ($have) continue;
        $wpdb->insert(kop_pp_table(), array(
            'ikey' => $key, 'facility_id' => $fid, 'doc_id' => (int) ($it['doc_id'] ?? 0),
            'doc_title' => mb_substr((string) ($it['doc_title'] ?? ''), 0, 255), 'doc_url' => mb_substr((string) ($it['doc_url'] ?? ''), 0, 600),
            'page' => (int) ($it['page'] ?? 0), 'topic' => $topic, 'value' => $value, 'value_orig' => $value,
            'quote' => (string) ($it['quote'] ?? ''), 'year' => (int) ($it['year'] ?? 0), 'checklist' => implode(',', $checks),
            'status' => 'pending', 'batch' => '', 'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        if ($wpdb->insert_id) $added++;
    }
    update_option('kop_pp_import_seen', $sig, false);
    return $added;
}

/* ---- Rows ----------------------------------------------------------------- */

/** Rows matching $where (column => value), in page order: document, page, id. */
function kop_pp_rows(array $where) {
    if (!kop_pp_ready()) return array();
    global $wpdb;
    $sql = array();
    $args = array();
    foreach ($where as $col => $val) {
        if (!in_array($col, array('id', 'facility_id', 'topic', 'status', 'batch', 'doc_id'), true)) continue;
        $sql[] = "{$col} = %s";
        $args[] = (string) $val;
    }
    $q = 'SELECT * FROM ' . kop_pp_table() . ($sql ? ' WHERE ' . implode(' AND ', $sql) : '') . ' ORDER BY year DESC, doc_id, page, id';
    $rows = $wpdb->get_results($args ? $wpdb->prepare($q, $args) : $q, ARRAY_A);
    return is_array($rows) ? $rows : array();
}

function kop_pp_set_status(array $ids, $status, $batch) {
    if (!$ids) return;
    global $wpdb;
    foreach ($ids as $id) {
        $wpdb->update(kop_pp_table(), array('status' => $status, 'batch' => (string) $batch), array('id' => (int) $id));
    }
}

function kop_pp_log() {
    $log = get_option('kop_pp_log', array());
    return is_array($log) ? $log : array();
}

function kop_pp_save_log(array $log) {
    update_option('kop_pp_log', $log, false);
}

function kop_pp_purge($fid) {
    $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $fid) : '';
    if ($url !== '') do_action('litespeed_purge_url', $url);
}

/* ---- Checklist boxes on the record ----------------------------------------- */

/** Is checklist key "section.hasX" ticked on $doc? */
function kop_pp_doc_has(array $doc, $key) {
    list($sec, $k) = explode('.', $key);
    $v = $doc[$sec][$k] ?? null;
    return $v === true || (is_string($v) && strtolower(trim($v)) === 'true');
}

/**
 * Tick $keys on record $fid where they are not ticked yet; returns the keys it
 * ticked ($on) or, with $on false, untick those keys. Saved through the
 * facility store (kop_wbf_save), one save.
 */
function kop_pp_doc_ticks($fid, array $keys, $on) {
    $keys = array_values(array_unique(array_filter($keys)));
    if (!$keys || !function_exists('kop_wbf_opts')) return array();
    $opts = kop_wbf_opts();
    $changed = array();
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $opts, $keys, $on, &$changed) {
        $stored = kop_facility_load((int) $fid, $opts);
        if (!$stored) return;
        $doc = $stored['doc'];
        $changed = kop_pp_doc_set($doc, $keys, $on);
        if ($changed) kop_wbf_save($doc, $opts);
    });
    return $changed;
}

/** Tick ($on) or untick $keys on $doc; returns the keys that changed. */
function kop_pp_doc_set(array &$doc, array $keys, $on) {
    $changed = array();
    foreach ($keys as $key) {
        if (kop_pp_clean_check($key) === '') continue;
        list($sec, $k) = explode('.', $key);
        if ($on && !kop_pp_doc_has($doc, $key)) {
            if (!isset($doc[$sec]) || !is_array($doc[$sec])) $doc[$sec] = array();
            $doc[$sec][$k] = true;
            $changed[] = $key;
        } elseif (!$on && kop_pp_doc_has($doc, $key)) {
            unset($doc[$sec][$k]);
            $changed[] = $key;
        }
    }
    return $changed;
}

/* ---- Decisions ------------------------------------------------------------- */

/**
 * Add the ticked lines of one program's topic ($picked: row ids) to its page,
 * leave the other waiting ones off, tick the record's boxes the added lines
 * show. Returns the message.
 */
function kop_pp_apply($fid, $topic, array $picked, $reviewer) {
    $fid = (int) $fid;
    $waiting = kop_pp_rows(array('facility_id' => $fid, 'topic' => $topic, 'status' => 'pending'));
    if (!$waiting) throw new RuntimeException('No lines of this card are waiting any more.');
    $picked = array_flip(array_map('intval', $picked));
    $add = array();
    $left = array();
    $checks = array();
    foreach ($waiting as $r) {
        if (isset($picked[(int) $r['id']])) {
            $add[] = (int) $r['id'];
            foreach (array_filter(explode(',', (string) $r['checklist'])) as $c) $checks[] = $c;
        } else {
            $left[] = (int) $r['id'];
        }
    }
    if (!$add) throw new RuntimeException('No line is ticked: tick the lines to add, or press Leave all off.');
    $batch = 'pp' . gmdate('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
    $ticks = kop_pp_doc_ticks($fid, $checks, true);
    kop_pp_set_status($add, 'added', $batch);
    kop_pp_set_status($left, 'left', $batch);
    $log = kop_pp_log();
    $log[$batch] = array('facility_id' => $fid, 'topic' => (string) $topic, 'added' => $add, 'left' => $left, 'ticks' => $ticks,
        'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    kop_pp_save_log($log);
    kop_pp_purge($fid);
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array($fid));
    $labels = function_exists('kop_facility_checklist_labels') ? kop_facility_checklist_labels() : array();
    return 'Added ' . count($add) . ' line' . (count($add) === 1 ? '' : 's') . ' to the facility page'
        . ($left ? ', left ' . count($left) . ' off' : '')
        . ($ticks ? '; ticked ' . implode(', ', array_map(function ($k) use ($labels) { return $labels[$k] ?? $k; }, $ticks)) . ' on the record' : '')
        . '. Undo is on the Added tab.';
}

/** Leave every waiting line of the card off the page. */
function kop_pp_leave($fid, $topic, $reviewer) {
    $waiting = kop_pp_rows(array('facility_id' => (int) $fid, 'topic' => $topic, 'status' => 'pending'));
    if (!$waiting) throw new RuntimeException('No lines of this card are waiting any more.');
    $batch = 'pp' . gmdate('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
    $ids = array_map('intval', array_column($waiting, 'id'));
    kop_pp_set_status($ids, 'left', $batch);
    $log = kop_pp_log();
    $log[$batch] = array('facility_id' => (int) $fid, 'topic' => (string) $topic, 'added' => array(), 'left' => $ids, 'ticks' => array(),
        'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    kop_pp_save_log($log);
    return 'Left ' . count($ids) . ' line' . (count($ids) === 1 ? '' : 's') . ' off. No page changed.';
}

/** The card's latest decision, or null. */
function kop_pp_last_batch($fid, $topic) {
    $found = null;
    foreach (kop_pp_log() as $id => $b) {
        if ((int) $b['facility_id'] === (int) $fid && (string) $b['topic'] === (string) $topic) $found = $id;
    }
    return $found;
}

/**
 * Undo the card's latest decision: its lines wait again (a line reworded since
 * keeps its wording), and the boxes it ticked come off unless another added
 * line of the record still shows them.
 */
function kop_pp_undo($fid, $topic) {
    $batch = kop_pp_last_batch($fid, $topic);
    if (!$batch) throw new RuntimeException('Nothing to undo.');
    $log = kop_pp_log();
    $b = $log[$batch];
    $ids = array_merge((array) $b['added'], (array) $b['left']);
    $back = array();
    foreach ($ids as $id) {
        foreach (kop_pp_rows(array('id' => (int) $id)) as $r) {
            if ($r['batch'] === $batch) $back[] = (int) $id;
        }
    }
    kop_pp_set_status($back, 'pending', '');
    $untick = array();
    if (!empty($b['ticks'])) {
        $still = array();
        foreach (kop_pp_rows(array('facility_id' => (int) $fid, 'status' => 'added')) as $r) {
            foreach (array_filter(explode(',', (string) $r['checklist'])) as $c) $still[$c] = true;
        }
        $untick = kop_pp_doc_ticks((int) $fid, array_values(array_filter((array) $b['ticks'], function ($k) use ($still) { return !isset($still[$k]); })), false);
    }
    unset($log[$batch]);
    kop_pp_save_log($log);
    if ($b['added']) {
        kop_pp_purge($fid);
        if (function_exists('kop_record_changed')) kop_record_changed('facility', array((int) $fid));
    }
    return 'Undone: ' . count($back) . ' line' . (count($back) === 1 ? '' : 's') . ' waiting again'
        . ($untick ? ', ' . count($untick) . ' box' . (count($untick) === 1 ? '' : 'es') . ' unticked on the record' : '') . '.';
}

/* ---- Pages ------------------------------------------------------------------ */

/** The added lines of a record, topic => [rows], in kop_pp_topics() order. */
function kop_pp_for_facility($fid) {
    $by = array();
    foreach (kop_pp_rows(array('facility_id' => (int) $fid, 'status' => 'added')) as $r) $by[$r['topic']][] = $r;
    $out = array();
    foreach (kop_pp_topics() as $t => $label) {
        if (!empty($by[$t])) $out[$t] = $by[$t];
    }
    return $out;
}

/** "<doc url>#page=N" for a line, or ''. */
function kop_pp_page_url(array $r) {
    $url = (string) $r['doc_url'];
    if ($url === '') return '';
    return (int) $r['page'] > 0 ? strtok($url, '#') . '#page=' . (int) $r['page'] : $url;
}

/** The app's copy: [{topic, label, lines: [{text, source_url, source_title, page, year}]}]. */
function kop_pp_for_app($fid) {
    $topics = kop_pp_topics();
    $out = array();
    foreach (kop_pp_for_facility($fid) as $t => $rows) {
        $out[] = array('topic' => $t, 'label' => $topics[$t], 'lines' => array_map(function ($r) {
            return array('text' => (string) $r['value'], 'source_url' => kop_pp_page_url($r), 'source_title' => (string) $r['doc_title'],
                'page' => (int) $r['page'], 'year' => (int) $r['year']);
        }, $rows));
    }
    return $out;
}

/** The document lines of the "Rules and practices" section: one list per topic, long lists folded. */
function kop_pp_section_html(array $groups, $show = 8) {
    $topics = kop_pp_topics();
    $line = function (array $r) {
        $url = kop_pp_page_url($r);
        $title = trim((string) $r['doc_title'] . ((int) $r['page'] > 0 ? ', page ' . (int) $r['page'] : ''));
        $attr = function_exists('kop_ie_attr') ? kop_ie_attr('pp:' . (int) $r['id'], 'this line') : '';
        return '<li' . $attr . '>' . esc_html((string) $r['value'])
            . ($url !== '' ? ' <span class="kop-fp-src"><a href="' . esc_url($url) . '" target="_blank" rel="noopener" title="' . esc_attr($title) . '">source</a></span>' : '')
            . '</li>';
    };
    ob_start();
    foreach ($groups as $t => $rows) :
        $first = array_slice($rows, 0, $show);
        $rest = array_slice($rows, $show);
        ?>
        <h3 class="kop-fp-subhead"><?php echo esc_html($topics[$t] ?? $t); ?></h3>
        <ul class="kop-pp-lines"><?php foreach ($first as $r) echo $line($r); // Escaped inside. ?></ul>
        <?php if ($rest) : ?>
            <details class="kop-pp-more">
                <summary><?php echo count($rest); ?> more</summary>
                <ul class="kop-pp-lines"><?php foreach ($rest as $r) echo $line($r); // Escaped inside. ?></ul>
            </details>
        <?php endif;
    endforeach;
    return ob_get_clean();
}

/** Where the lines come from, under the section heading: "From the program's own papers: Student Manual (2010), ...". */
function kop_pp_sources_line(array $groups) {
    $docs = array();
    foreach ($groups as $rows) {
        foreach ($rows as $r) $docs[(int) $r['doc_id']] = (string) $r['doc_title'];
    }
    return $docs ? 'From the program\'s own papers in our library: ' . implode('; ', array_unique($docs)) . '.' : '';
}

/* ---- Edit in place: pp:<id> ------------------------------------------------- */

function kop_pp_ie_load(array $p) {
    $r = kop_pp_rows(array('id' => (int) ($p[0] ?? 0)))[0] ?? null;
    if (!$r) throw new RuntimeException('That line is gone.');
    return array(
        'title'  => 'A line from ' . $r['doc_title'],
        'help'   => 'What the document says, in plain words. The quote it rests on: "' . $r['quote'] . '" (page ' . (int) $r['page'] . ').',
        'fields' => array(
            kop_ie_field('value', 'The line', 'textarea', (string) $r['value'], array('rows' => 3)),
            kop_ie_field('off', 'Take this line off the page', 'bool', false),
        ),
    );
}

function kop_pp_ie_save(array $p, array $v) {
    $r = kop_pp_rows(array('id' => (int) ($p[0] ?? 0)))[0] ?? null;
    if (!$r) throw new RuntimeException('That line is gone.');
    global $wpdb;
    $off = $v['off'] ?? false;
    if ($off === true || $off === 'true' || $off === 1 || $off === '1') {
        $wpdb->update(kop_pp_table(), array('status' => 'left'), array('id' => (int) $r['id']));
        kop_pp_purge($r['facility_id']);
        return array('message' => 'Taken off the page. It is listed under Left off in Review inbox > Rules from documents.');
    }
    $value = trim((string) ($v['value'] ?? ''));
    if ($value === '') throw new RuntimeException('The line is empty: tick "Take this line off the page" instead.');
    if ($value === $r['value']) return array('message' => 'No change.');
    $wpdb->update(kop_pp_table(), array('value' => $value), array('id' => (int) $r['id']));
    kop_pp_purge($r['facility_id']);
    return array('message' => 'Saved.');
}

add_filter('kop_inline_edit_sources', static function ($sources) {
    $sources['pp'] = array('load' => 'kop_pp_ie_load', 'save' => 'kop_pp_ie_save');
    return $sources;
});
