<?php
/**
 * What the industry's own podcasts say on air (docs/PLAN.md 3.18).
 *
 * kop-pipeline transcribes the shows locally (scripts/podcast-transcribe.py)
 * and readers pull out, per episode, every on-air statement by an industry
 * professional that belongs on the record (restraint, transport, isolation,
 * deprivation, discrediting kids, minimising deaths, ...) and facts about
 * programs and people. scripts/podcast-facts.py check tests every quote
 * against the transcript and writes one file per show:
 *
 *   ~/kop-import/podcasts/<show>.json   (a copy of tmp/podcasts/<show>/proposals.json)
 *     {show, built, episodes: {stem: {title, date, link, audio}},
 *      items: [{kind: statement|person|program|incident, quote, time, seconds, audio_at, episode,
 *               speaker, speaker_role, speaker_basis, attribution, programs, affiliation, category, stance, ...}]}
 *
 * The owner reviews them at Review inbox > Podcast statements
 * (inc/review-inbox/podcasts.php). Whisper mishears names and has no speaker
 * labels, so each card has the audio from the quote's second, the words to
 * correct, who said it (a person id from {prefix}kop_people, suggested from
 * the speaker's name) and the programs and companies it is about (suggested
 * from the names the reader gave). Approve stores those links; Reject drops
 * the item; Undo puts either back. Nothing here is public (owner, 2026-10-13):
 * approved statements show on the person's KOP Tools > People screen and, to
 * administrators only, on the facility and company pages they are linked to.
 *
 * Rows: {prefix}kop_podcast_items, one per item, keyed by show, episode,
 * second and quote, so a new export adds only new items and never changes a
 * decided one. 'records' holds the linked records as finder tokens: a
 * facility's bare id, "company:<id>".
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_POD_DB_VERSION = '1';

function kop_pod_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_podcast_items';
}

function kop_pod_install($force = false) {
    if (!$force && get_option('kop_pod_db') === KOP_POD_DB_VERSION) return;
    global $wpdb;
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_pod_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ikey CHAR(40) NOT NULL,
        show_key VARCHAR(40) NOT NULL DEFAULT '',
        episode VARCHAR(191) NOT NULL DEFAULT '',
        kind VARCHAR(12) NOT NULL DEFAULT 'statement',
        seconds INT UNSIGNED NOT NULL DEFAULT 0,
        quote TEXT NOT NULL,
        quote_orig TEXT NOT NULL,
        speaker VARCHAR(255) NOT NULL DEFAULT '',
        person_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        records VARCHAR(600) NOT NULL DEFAULT '',
        data LONGTEXT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        decided_by VARCHAR(100) NOT NULL DEFAULT '',
        decided_at DATETIME NULL,
        undo LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ikey (ikey),
        KEY status (status),
        KEY person_id (person_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_pod_db', KOP_POD_DB_VERSION, false);
}
add_action('admin_init', 'kop_pod_install');

function kop_pod_ready() {
    static $ready = null;
    if ($ready === null) {
        global $wpdb;
        $ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', kop_pod_table())) === kop_pod_table();
    }
    return $ready;
}

/** Item kind => label. */
function kop_pod_kinds() {
    return array('statement' => 'On-air statement', 'person' => 'Person fact', 'program' => 'Program fact', 'incident' => 'Incident');
}

/** Statement category => words (kop-pipeline tmp/podcasts/INSTRUCTIONS.md, written by podcast-facts.py). */
function kop_pod_categories() {
    return array(
        'restraint' => 'restraint', 'transport' => 'transport', 'isolation' => 'isolation', 'deprivation' => 'deprivation',
        'family_contact' => 'cutting kids off from parents', 'discrediting_youth' => 'discrediting kids',
        'discrediting_survivors' => 'discrediting survivors and critics', 'deaths_and_injuries' => 'deaths and injuries',
        'coercion_control' => 'coercion and control', 'unqualified_staff' => 'unqualified staff', 'regulation' => 'dodging oversight',
        'business' => 'the business', 'deception' => 'deception', 'insider_knowledge' => 'insider knowledge', 'other' => 'other',
    );
}

/* ---- Import --------------------------------------------------------------- */

function kop_pod_import_dir() {
    return defined('KOP_PODCAST_IMPORT_DIR') ? rtrim(KOP_PODCAST_IMPORT_DIR, '/') : dirname(rtrim(ABSPATH, '/')) . '/kop-import/podcasts';
}

/** The show files: <dir>/<show>.json, or <dir>/<show>/proposals.json (the pipeline's own layout). */
function kop_pod_import_files() {
    $dir = kop_pod_import_dir();
    $files = array_merge(glob($dir . '/*.json') ?: array(), glob($dir . '/*/proposals.json') ?: array());
    sort($files);
    return $files;
}

/** One quote can carry several items (two people named, two facts about a program): the key holds what each is about. */
function kop_pod_item_key($show, array $it) {
    $about = array();
    foreach (array('speaker', 'person', 'program', 'field', 'value', 'role', 'event', 'summary') as $k) {
        $about[] = is_scalar($it[$k] ?? '') ? (string) ($it[$k] ?? '') : wp_json_encode($it[$k]);
    }
    return sha1($show . '|' . ($it['episode'] ?? '') . '|' . (int) ($it['seconds'] ?? 0) . '|' . ($it['kind'] ?? '') . '|'
        . trim((string) ($it['quote'] ?? '')) . '|' . implode('|', $about));
}

/**
 * Add the export's new items (an item already here is left as it is, decided
 * or not). Each item keeps its whole entry plus its episode's title, date and
 * links in 'data'. Runs when a file changed since the last import. Returns
 * items added.
 */
function kop_pod_import($force = false) {
    if (!kop_pod_ready()) return 0;
    $files = kop_pod_import_files();
    $sig = md5(implode('|', array_map(function ($f) { return $f . ':' . filemtime($f) . ':' . filesize($f); }, $files)));
    if (!$force && get_option('kop_pod_import_seen') === $sig) return 0;
    global $wpdb;
    $kinds = kop_pod_kinds();
    $added = 0;
    foreach ($files as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) continue;
        $show = sanitize_key((string) ($data['show'] ?? basename($file, '.json')));
        $episodes = (array) ($data['episodes'] ?? array());
        foreach ((array) ($data['items'] ?? array()) as $it) {
            if (!is_array($it) || !isset($kinds[$it['kind'] ?? '']) || trim((string) ($it['quote'] ?? '')) === '') continue;
            $key = kop_pod_item_key($show, $it);
            if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . kop_pod_table() . ' WHERE ikey = %s', $key))) continue;
            $ep = (array) ($episodes[$it['episode'] ?? ''] ?? array());
            $it['show'] = $show;
            $it['episode_title'] = (string) ($ep['title'] ?? $it['episode'] ?? '');
            $it['episode_date'] = (string) ($ep['date'] ?? '');
            $it['episode_link'] = (string) ($ep['link'] ?? '');
            $quote = trim((string) $it['quote']);
            $speaker = trim((string) ($it['kind'] === 'statement' ? ($it['speaker'] ?? '') : ($it['person'] ?? '')));
            $wpdb->insert(kop_pod_table(), array(
                'ikey' => $key, 'show_key' => $show, 'episode' => mb_substr((string) ($it['episode'] ?? ''), 0, 191),
                'kind' => (string) $it['kind'], 'seconds' => max(0, (int) ($it['seconds'] ?? 0)),
                'quote' => $quote, 'quote_orig' => $quote, 'speaker' => mb_substr($speaker, 0, 255),
                'person_id' => 0, 'records' => '', 'data' => wp_json_encode($it), 'status' => 'pending',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
            if ($wpdb->insert_id) $added++;
        }
    }
    update_option('kop_pod_import_seen', $sig, false);
    return $added;
}

/* ---- Rows ----------------------------------------------------------------- */

function kop_pod_row($id) {
    if (!kop_pod_ready()) return null;
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_pod_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    return $r ? kop_pod_hydrate($r) : null;
}

function kop_pod_hydrate(array $r) {
    $r['id'] = (int) $r['id'];
    $r['person_id'] = (int) $r['person_id'];
    $r['seconds'] = (int) $r['seconds'];
    $d = json_decode((string) $r['data'], true);
    $r['d'] = is_array($d) ? $d : array();
    return $r;
}

/** "0:08:48" for a second count. */
function kop_pod_time($s) {
    $s = (int) $s;
    return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
}

/** The episode's audio from the quote's second ("...mp3#t=528"). */
function kop_pod_audio_url(array $r) {
    $at = (string) ($r['d']['audio_at'] ?? '');
    if ($at !== '') return $at;
    $audio = (string) ($r['d']['audio'] ?? '');
    return $audio !== '' ? strtok($audio, '#') . '#t=' . (int) $r['seconds'] : '';
}

/** Finder tokens of a row's linked records: ['123', 'company:45']. */
function kop_pod_tokens($records) {
    return array_values(array_filter(array_map('trim', explode(',', (string) $records)), function ($t) {
        return preg_match('/^(\d+|company:\d+)$/', $t);
    }));
}

/** [{id: token, name}] for the card's chips. */
function kop_pod_token_chips(array $tokens) {
    $out = array();
    foreach ($tokens as $t) {
        list($kind, $id) = ctype_digit($t) ? array('facility', (int) $t) : array('company', (int) substr($t, 8));
        $name = function_exists('kop_record_field_name') ? kop_record_field_name($kind, $id) : '';
        $out[] = array('id' => $t, 'name' => ($name !== '' ? $name : '#' . $id) . ($kind === 'company' ? ' (company)' : ''));
    }
    return $out;
}

/* ---- Suggestions ---------------------------------------------------------- */

/** The person id a name already has in the industry pool, 0 if none. */
function kop_pod_person_for($name) {
    if (trim((string) $name) === '' || !function_exists('kop_people_load')) return 0;
    static $state = null;
    if ($state === null) $state = kop_people_load();
    return kop_people_find($state, $name, 'industry');
}

function kop_pod_person_name($id) {
    if ((int) $id <= 0) return '';
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . kop_people_table('people') . ' WHERE id = %d', (int) $id));
}

/** "Will White #123" (the lookup's value), or the bare name. */
function kop_pod_person_value($name, $id) {
    $id = (int) $id;
    if ($id <= 0) return (string) $name;
    $n = kop_pod_person_name($id);
    return ($n !== '' ? $n : (string) $name) . ' #' . $id;
}

/** name key => token over every company's name, for exact matches only. */
function kop_pod_company_index() {
    static $index = null;
    if ($index !== null) return $index;
    $index = array();
    global $wpdb;
    $rows = $wpdb->get_results('SELECT id, name, unique_name FROM ' . $wpdb->prefix . 'kop_operators', ARRAY_A);
    foreach ((array) $rows as $o) {
        foreach (array($o['name'], $o['unique_name']) as $n) {
            $k = function_exists('kop_autoconn_key') ? kop_autoconn_key($n) : strtolower(trim((string) $n));
            if ($k !== '') $index[$k]['company:' . (int) $o['id']] = true;
        }
    }
    return $index;
}

/**
 * The records an item names, each found by an exact name that is one record's
 * (a program's name, current, other or past name; a company's name). Never a
 * guess: a name two records share suggests nothing.
 */
function kop_pod_suggest_records(array $d) {
    $names = array();
    foreach ((array) ($d['programs'] ?? array()) as $p) $names[] = is_array($p) ? ($p['program'] ?? '') : $p;
    foreach ((array) ($d['affiliation'] ?? array()) as $a) $names[] = is_array($a) ? ($a['program'] ?? '') : $a;
    foreach (array('program', 'from_program') as $k) $names[] = $d[$k] ?? '';
    $tokens = array();
    $facilities = array();
    if (function_exists('kop_autoconn_name_index') && function_exists('kop_rinbox_pdo')) {
        try {
            $facilities = kop_autoconn_name_index(kop_rinbox_pdo());
        } catch (Throwable $e) {
            $facilities = array();
        }
    }
    $companies = kop_pod_company_index();
    foreach ($names as $n) {
        $k = function_exists('kop_autoconn_key') ? kop_autoconn_key($n) : strtolower(trim((string) $n));
        if ($k === '') continue;
        $hits = array_keys($facilities[$k] ?? array());
        if (count($hits) === 1) {
            $tokens[(string) $hits[0]] = true;
            continue;
        }
        $hits = array_keys($companies[$k] ?? array());
        if (count($hits) === 1) $tokens[$hits[0]] = true;
    }
    return array_map('strval', array_keys($tokens));
}

/* ---- Decisions ------------------------------------------------------------ */

/** Person id from the lookup's value: "Name #123" -> 123; a bare name -> its id, made new when it has none. */
function kop_pod_resolve_person($value, &$made = 0) {
    $value = trim((string) $value);
    if ($value === '') return 0;
    if (preg_match('/#(\d+)\s*$/', $value, $m)) {
        $id = (int) $m[1];
        if (kop_pod_person_name($id) === '') throw new RuntimeException('No person #' . $id . ' on file.');
        return $id;
    }
    if (!function_exists('kop_people_load')) return 0;
    $state = kop_people_load();
    $created = 0;
    $id = kop_people_assign($state, $value, 0, array(), $created, 'industry');
    if (!$id) throw new RuntimeException('"' . $value . '" cannot be a person id: give a first and last name.');
    if ($created) {
        // Read the new row back by its name rather than trusting the insert id.
        $id = kop_people_find(kop_people_load(), $value, 'industry') ?: $id;
        $made = $id;
    }
    return $id;
}

/**
 * Approve item $id with the words, speaker and records as the card has them.
 * A person made for it is noted, so Undo removes them again while nothing
 * else names them.
 */
function kop_pod_approve($id, array $p, $reviewer) {
    $r = kop_pod_row($id);
    if (!$r) throw new RuntimeException('This item is gone.');
    if ($r['status'] !== 'pending') throw new RuntimeException('This item has been decided already.');
    $quote = trim((string) ($p['quote'] ?? $r['quote']));
    if ($quote === '') throw new RuntimeException('The words cannot be empty.');
    $made = 0;
    $person = kop_pod_resolve_person($p['person'] ?? '', $made);
    $tokens = array();
    foreach ((array) ($p['records'] ?? array()) as $t) {
        $t = trim((string) $t);
        if (preg_match('/^(\d+|company:\d+)$/', $t)) $tokens[$t] = true;
    }
    // array_keys() turns '123' into 123: tokens stay strings.
    $tokens = array_map('strval', array_keys($tokens));
    $undo = array('quote' => $r['quote'], 'speaker' => $r['speaker'], 'person_id' => $r['person_id'], 'records' => $r['records'], 'made_person' => $made);
    global $wpdb;
    $wpdb->update(kop_pod_table(), array(
        'quote' => $quote, 'person_id' => $person, 'speaker' => $person ? kop_pod_person_name($person) : $r['speaker'],
        'records' => implode(',', $tokens), 'status' => 'approved', 'decided_by' => (string) $reviewer,
        'decided_at' => gmdate('Y-m-d H:i:s'), 'undo' => wp_json_encode($undo),
    ), array('id' => (int) $id));
    kop_pod_purge($tokens);
    $bits = array();
    if ($person) $bits[] = 'person #' . $person . ($made ? ' (new)' : '');
    if ($tokens) $bits[] = count($tokens) . ' record' . (count($tokens) === 1 ? '' : 's');
    return 'Approved' . ($bits ? ' and linked to ' . implode(' and ', $bits) : ', linked to no one yet') . '. Shown only to administrators. Undo on the Approved tab.';
}

function kop_pod_reject($id, $reviewer) {
    $r = kop_pod_row($id);
    if (!$r) throw new RuntimeException('This item is gone.');
    if ($r['status'] !== 'pending') throw new RuntimeException('This item has been decided already.');
    global $wpdb;
    $wpdb->update(kop_pod_table(), array('status' => 'rejected', 'decided_by' => (string) $reviewer, 'decided_at' => gmdate('Y-m-d H:i:s'),
        'undo' => wp_json_encode(array())), array('id' => (int) $id));
    return 'Rejected. Nothing on the site changed.';
}

/** Put a decided item back to waiting, exactly as it was before. */
function kop_pod_undo($id) {
    $r = kop_pod_row($id);
    if (!$r || $r['status'] === 'pending') throw new RuntimeException('Nothing to undo.');
    $u = json_decode((string) $r['undo'], true);
    $u = is_array($u) ? $u : array();
    global $wpdb;
    $wpdb->update(kop_pod_table(), array(
        'quote' => (string) ($u['quote'] ?? $r['quote']), 'speaker' => (string) ($u['speaker'] ?? $r['speaker']),
        'person_id' => (int) ($u['person_id'] ?? 0), 'records' => (string) ($u['records'] ?? ''),
        'status' => 'pending', 'decided_by' => '', 'decided_at' => null, 'undo' => null,
    ), array('id' => (int) $id));
    $gone = '';
    $made = (int) ($u['made_person'] ?? 0);
    if ($made > 0 && kop_pod_person_unused($made)) {
        $wpdb->delete(kop_people_table('people'), array('id' => $made));
        $gone = ' The person id it made (#' . $made . ') is removed again.';
    }
    if ($r['status'] === 'approved') kop_pod_purge(kop_pod_tokens($r['records']));
    return 'Undone: waiting again.' . $gone;
}

/** True when nothing but this queue names person $id (no record role, no merge, no other item). */
function kop_pod_person_unused($id) {
    global $wpdb;
    $roles = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_people_table('roles') . ' WHERE person_id = %d', (int) $id));
    $merged = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_people_table('people') . ' WHERE merged_into = %d', (int) $id));
    $items = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_pod_table() . ' WHERE person_id = %d', (int) $id));
    return !$roles && !$merged && !$items;
}

function kop_pod_purge(array $tokens) {
    foreach ($tokens as $t) {
        $url = '';
        if (ctype_digit($t)) {
            $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $t) : '';
        } elseif (function_exists('kop_operator_page_url')) {
            $url = (string) kop_operator_page_url((int) substr($t, 8));
        }
        if ($url !== '') do_action('litespeed_purge_url', $url);
    }
}

/* ---- Readers (administrators only) ---------------------------------------- */

/** Review inbox > Podcast statements. */
function kop_pod_inbox_url() {
    $url = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-admin-submissions.php') : '';
    return add_query_arg('type', 'podcasts', $url !== '' ? $url : home_url('/admin-submissions/'));
}

/** Approved items linked to record token $token ('123' or 'company:45'), newest episode first. */
function kop_pod_for_record($token) {
    if (!kop_pod_ready()) return array();
    global $wpdb;
    $token = (string) $token;
    $t = $wpdb->esc_like($token);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_pod_table()
        . " WHERE status = 'approved' AND (records = %s OR records LIKE %s OR records LIKE %s OR records LIKE %s)",
        $token, $t . ',%', '%,' . $t, '%,' . $t . ',%'), ARRAY_A);
    return kop_pod_sorted((array) $rows);
}

/** Approved items said by or about person $id (and the ids merged into them). */
function kop_pod_for_person($id) {
    if (!kop_pod_ready()) return array();
    global $wpdb;
    $ids = array((int) $id);
    foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . kop_people_table('people') . ' WHERE merged_into = %d', (int) $id)) as $m) $ids[] = (int) $m;
    $rows = $wpdb->get_results('SELECT * FROM ' . kop_pod_table() . " WHERE status = 'approved' AND person_id IN (" . implode(',', $ids) . ')', ARRAY_A);
    return kop_pod_sorted((array) $rows);
}

function kop_pod_sorted(array $rows) {
    $rows = array_map('kop_pod_hydrate', $rows);
    usort($rows, function ($a, $b) {
        return strcmp((string) ($b['d']['episode_date'] ?? ''), (string) ($a['d']['episode_date'] ?? '')) ?: $a['seconds'] <=> $b['seconds'];
    });
    return $rows;
}

/** One approved item as a list entry: the words, who said it, the episode and a link to the audio at that second. */
function kop_pod_line_html(array $r, $with_speaker = true) {
    $d = $r['d'];
    $who = $with_speaker && $r['speaker'] !== '' ? $r['speaker'] : '';
    if ($who !== '' && $r['person_id'] && function_exists('kop_people_admin_url')) {
        $who = '<a href="' . esc_url(kop_people_admin_url(array('person' => $r['person_id']))) . '">' . esc_html($who) . '</a>';
    } else {
        $who = esc_html($who);
    }
    $ep = trim((string) ($d['episode_title'] ?? '') . (!empty($d['episode_date']) ? ' (' . $d['episode_date'] . ')' : ''));
    $audio = kop_pod_audio_url($r);
    $kinds = kop_pod_kinds();
    return '<li class="kop-pod-line"><blockquote>' . esc_html($r['quote']) . '</blockquote><p class="kop-fp-count">'
        . ($r['kind'] !== 'statement' ? esc_html($kinds[$r['kind']] ?? $r['kind']) . '. ' : '')
        . ($who !== '' ? $who . ', ' : '') . esc_html($ep)
        . ($audio !== '' ? ', <a href="' . esc_url($audio) . '" target="_blank" rel="noopener">listen at ' . esc_html(kop_pod_time($r['seconds'])) . '</a>' : '')
        . '</p></li>';
}

/** The admin-only section on a facility or company page, '' for everyone else or when nothing is linked. */
function kop_pod_section_html($token) {
    if (!current_user_can('manage_options')) return '';
    // A program's page also lists what was linked to records merged into it since (Merge Duplicates).
    $tokens = ctype_digit((string) $token) && function_exists('kop_facility_merge_expand_ids')
        ? array_map('strval', kop_facility_merge_expand_ids(array((int) $token))) : array((string) $token);
    $rows = array();
    foreach ($tokens as $t) {
        foreach (kop_pod_for_record($t) as $r) $rows[$r['id']] = $r;
    }
    return $rows ? kop_pod_section_body(array_values($rows)) : '';
}

/** The section itself, for the approved items $rows. */
function kop_pod_section_body(array $rows) {
    $html = '<section class="kop-fp-section kop-pod-section" id="podcast-statements"><h2>On air: the industry\'s own podcasts</h2>'
        . '<p class="kop-fp-count">Only administrators see this. ' . count($rows) . ' approved item' . (count($rows) === 1 ? '' : 's')
        . ' from machine transcripts: listen before quoting.</p><ul class="kop-pod-lines">';
    foreach ($rows as $r) $html .= kop_pod_line_html($r);
    return $html . '</ul></section>';
}
