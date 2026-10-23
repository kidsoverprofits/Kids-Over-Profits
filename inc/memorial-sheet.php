<?php
/**
 * KOP Tools > Memorial sheet: In Loving Memory (memorial_victims) against the owner's private "Death Toll"
 * Google Sheet, worked through one decision at a time (docs/plan/4.3-records-names-and-data-quality.md item 15).
 *
 * The server can't read the sheet, so the comparison ships as seeds/memorial-sheet.json (kop-pipeline
 * scripts/build-memorial-sheet-seed.py, from a snapshot of the sheet); the page's side is read live, so a line
 * the page already agrees with is settled by itself. Three tabs:
 *   Conflicts      one line per field that differs: keep the page's, take the sheet's, or type a value. Saved
 *                  through the inline edit's row save (kop_ie_row_save()), which fires kop_record_changed().
 *   Add            sheet rows the page doesn't have: Add writes a published row (only with a source: a sheet
 *                  row with no source stays out), Skip remembers.
 *   Only on the page   the page's rows the sheet lacks, for the record.
 * Every decision is an entry in option kop_memorial_sheet_log (key c:<row>:<field> or a:<row>) holding the
 * values before and after, so Undo puts back exactly what was there. Restraint deaths are never relabelled:
 * the cause filter is never changed here, and a restraint row's cause text has to keep saying restraint.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/admin-kit.php';

const KOP_MS_PER_PAGE = 20;

add_filter('kop_admin_kit_pages', function ($pages) {
    $pages[] = 'kop-memorial-sheet';
    return $pages;
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Memorial sheet', 'Memorial sheet', 'manage_options', 'kop-memorial-sheet', 'kop_ms_page');
}, 30);

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'kop-memorial-sheet') === false) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('kop-memorial-sheet', $uri . '/css/memorial-sheet.css', array('kop-admin-kit'), (string) @filemtime($dir . '/css/memorial-sheet.css'));
    wp_enqueue_script('kop-memorial-sheet', $uri . '/js/memorial-sheet.js', array('kop-admin-kit'), (string) @filemtime($dir . '/js/memorial-sheet.js'), true);
    wp_localize_script('kop-memorial-sheet', 'KOP_MEMORIAL_SHEET', array('nonce' => wp_create_nonce('kop_memorial_sheet')));
});

/* ---- Data -------------------------------------------------------------------------------------------- */

function kop_ms_seed() {
    static $seed = null;
    if ($seed === null) {
        $path = get_stylesheet_directory() . '/seeds/memorial-sheet.json';
        $seed = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        $seed = is_array($seed) ? $seed : array();
        $seed += array('conflicts' => array(), 'add' => array(), 'page_only' => array(), 'page_only_notes' => array(), 'seeded_rows' => array());
    }
    return $seed;
}

function kop_ms_log() {
    $log = get_option('kop_memorial_sheet_log');
    return is_array($log) ? $log : array();
}

function kop_ms_log_put($key, $entry) {
    $log = kop_ms_log();
    if ($entry === null) {
        unset($log[$key]);
    } else {
        $log[$key] = $entry + array('user' => get_current_user_id(), 'at' => current_time('mysql'));
    }
    update_option('kop_memorial_sheet_log', $log, false);
}

/** Every memorial row by id (the page's side, live). */
function kop_ms_rows() {
    static $rows = null;
    if ($rows === null) {
        $rows = array();
        foreach (kop_ie_referrer_pdo()->query('SELECT * FROM memorial_victims')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(int) $r['id']] = $r;
        }
    }
    return $rows;
}

function kop_ms_fields() {
    return array(
        'name'     => array('Name', array('name')),
        'age'      => array('Age', array('age')),
        'date'     => array('Date of death', array('date_of_death', 'date_precision')),
        'program'  => array('Program', array('program')),
        'location' => array('Where (state or region)', array('location')),
        'cause'    => array('Cause, as reported', array('cause_of_death')),
        'source'   => array('Source link', array('source_url')),
    );
}

function kop_ms_is_restraint(array $row) {
    return ($row['cause_category'] ?? '') === 'restraint';
}

function kop_ms_says_restraint($text) {
    return (bool) preg_match('/restrain|held down|hold|prone|pinned|bivy|sat on|face.?down/i', (string) $text);
}

/** A restraint death's cause that says so may not be replaced by one that doesn't (the filter is never changed here). */
function kop_ms_drops_restraint(array $row, $text) {
    return kop_ms_is_restraint($row) && kop_ms_says_restraint($row['cause_of_death']) && !kop_ms_says_restraint($text);
}

/** "May 5, 2003" at the stored precision (the page's own wording, kop_memorial_date() when it is loaded). */
function kop_ms_date_label($date, $precision) {
    if (!$date) return '';
    if (function_exists('kop_memorial_date')) return kop_memorial_date($date, $precision);
    $t = strtotime($date);
    switch ($precision) {
        case 'year': return gmdate('Y', $t);
        case 'month': return gmdate('F Y', $t);
        case 'unknown': return '';
    }
    return gmdate('F j, Y', $t);
}

function kop_ms_page_value($field, array $row) {
    switch ($field) {
        case 'date':
            return $row['date_of_death'] ? kop_ms_date_label($row['date_of_death'], $row['date_precision']) . ' (' . $row['date_precision'] . ')' : '';
        case 'cause':
            $cats = kop_memorial_causes('all');
            $cat = (string) ($row['cause_category'] ?? '');
            return (string) $row['cause_of_death'] . ($cat !== '' ? ' [filter: ' . ($cats[$cat] ?? $cat) . ']' : '');
    }
    $col = kop_ms_fields()[$field][1][0];
    return (string) ($row[$col] ?? '');
}

/** True when the page already holds what the sheet's value would write. */
function kop_ms_matches(array $set, array $row) {
    foreach ($set as $col => $val) {
        if ((string) ($row[$col] ?? '') !== (string) $val) return false;
    }
    return true;
}

/** Each conflict line with its key, page row and state: open, decided (log entry) or agrees (page already has it). */
function kop_ms_conflict_lines() {
    $rows = kop_ms_rows();
    $log = kop_ms_log();
    $out = array();
    foreach (kop_ms_seed()['conflicts'] as $c) {
        $row = $rows[(int) $c['id']] ?? null;
        foreach ($c['lines'] as $l) {
            $key = 'c:' . (int) $c['row'] . ':' . $l['field'];
            $state = isset($log[$key]) ? 'decided' : ($row === null ? 'gone' : (kop_ms_matches($l['set'], $row) ? 'agrees' : 'open'));
            $out[$key] = array('key' => $key, 'c' => $c, 'l' => $l, 'row' => $row, 'state' => $state, 'log' => $log[$key] ?? null);
        }
    }
    return $out;
}

function kop_ms_norm_name($s) {
    $s = strtolower(preg_replace('/\(.*?\)|\b(jr|sr|ii|iii|iv)\b\.?/i', ' ', (string) $s));
    $p = array_values(array_filter(explode(' ', preg_replace('/[^a-z ]/', ' ', $s)), function ($w) { return strlen($w) > 1; }));
    if (count($p) < 2 || in_array($p[0], array('unknown', 'unnamed', 'boy', 'girl', 'unidentified'), true)) return '';
    return $p[0] . ' ' . $p[count($p) - 1];
}

/** Each sheet-only row with its state: open, added, skipped, seeded (in seeds/memorial.json), or on the page now. */
function kop_ms_add_rows() {
    $log = kop_ms_log();
    $seeded = array_flip(array_map('intval', kop_ms_seed()['seeded_rows']));
    $names = array();
    foreach (kop_ms_rows() as $id => $r) {
        $n = kop_ms_norm_name($r['name']);
        if ($n !== '') $names[$n][] = $id;
    }
    $out = array();
    foreach (kop_ms_seed()['add'] as $a) {
        $key = 'a:' . (int) $a['row'];
        $entry = $log[$key] ?? null;
        $state = $entry ? $entry['act'] : (isset($seeded[(int) $a['row']]) ? 'seeded' : 'open');
        $n = kop_ms_norm_name($a['name']);
        $out[$key] = array('key' => $key, 'a' => $a, 'state' => $state, 'log' => $entry, 'maybe' => $n !== '' ? ($names[$n] ?? array()) : array());
    }
    return $out;
}

/* ---- Writes ------------------------------------------------------------------------------------------ */

/** What a choice on one line writes: column => value (empty for "keep the page's"). */
function kop_ms_choice_set(array $l, $choice, $value, $precision) {
    if ($choice === 'page') return array();
    if ($choice === 'sheet') return $l['set'];
    $value = trim((string) $value);
    if ($value === '') throw new RuntimeException('Type a value, or pick one side.');
    switch ($l['field']) {
        case 'date':
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new RuntimeException('A date as YYYY-MM-DD (01 for an unknown month or day).');
            $precision = in_array($precision, array('day', 'month', 'year', 'unknown'), true) ? $precision : 'day';
            return array('date_of_death' => $value, 'date_precision' => $precision);
        case 'age':
            if (!ctype_digit($value)) throw new RuntimeException('An age is a whole number.');
            return array('age' => (int) $value);
        case 'source':
            if (!preg_match('#^https?://\S+$#i', $value)) throw new RuntimeException('A source is a web address starting http.');
            break;
    }
    return array(kop_ms_fields()[$l['field']][1][0] => $value);
}

function kop_ms_settle($key, $choice, $value, $precision) {
    $lines = kop_ms_conflict_lines();
    if (!isset($lines[$key])) throw new RuntimeException('That line is no longer on the list. Reload the page.');
    $x = $lines[$key];
    if ($x['state'] === 'decided') throw new RuntimeException('Already decided.');
    if (!$x['row']) throw new RuntimeException('The page\'s row is gone.');
    if (!in_array($choice, array('page', 'sheet', 'typed'), true)) throw new RuntimeException('Pick the sheet, the page or type a value.');
    $row = $x['row'];
    $set = kop_ms_choice_set($x['l'], $choice, $value, $precision);
    if ($x['l']['field'] === 'cause' && $set && kop_ms_drops_restraint($row, $set['cause_of_death'])) {
        throw new RuntimeException('A restraint death stays restraint: the page\'s cause says so and this one doesn\'t. Type a value that keeps the restraint and adds what the sheet has.');
    }
    $old = array();
    foreach ($set as $col => $v) $old[$col] = $row[$col];
    if ($set) {
        kop_ie_row_save(array('memorial', (int) $row['id']), $set, array('source' => 'memorial-sheet'));
    }
    kop_ms_log_put($key, array('act' => $choice, 'id' => (int) $row['id'], 'old' => $old, 'new' => $set));
    return $choice === 'page' ? 'Kept the page\'s.' : 'Saved.';
}

function kop_ms_clean_add(array $in) {
    $s = function ($k) use ($in) { return trim(preg_replace('/[ \t]+/', ' ', str_replace(array("\r\n", "\r"), "\n", (string) ($in[$k] ?? '')))); };
    $v = array(
        'name' => $s('name') !== '' ? $s('name') : 'Unknown',
        'age' => $s('age'),
        'program' => $s('program'),
        'date_of_death' => $s('date_of_death'),
        'date_precision' => $s('date_precision'),
        'cause_of_death' => $s('cause_of_death'),
        'cause_category' => $s('cause_category'),
        'location' => $s('location'),
        'source_name' => $s('source_name'),
        'source_url' => $s('source_url'),
        'notes' => $s('notes'),
    );
    if (!preg_match('#^https?://\S+$#i', $v['source_url'])) {
        throw new RuntimeException('No source: a sheet row with no source stays out. Find one and paste its link first.');
    }
    if ($v['age'] !== '' && !ctype_digit($v['age'])) throw new RuntimeException('An age is a whole number.');
    if ($v['date_of_death'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['date_of_death'])) throw new RuntimeException('A date as YYYY-MM-DD, or empty.');
    if (!in_array($v['date_precision'], array('day', 'month', 'year', 'unknown'), true)) $v['date_precision'] = $v['date_of_death'] !== '' ? 'day' : 'unknown';
    if ($v['date_of_death'] === '') $v['date_precision'] = 'unknown';
    if ($v['cause_category'] !== '' && !array_key_exists($v['cause_category'], kop_memorial_causes('all'))) throw new RuntimeException('Pick a cause from the list.');
    return $v;
}

function kop_ms_add($key, array $in) {
    $rows = kop_ms_add_rows();
    if (!isset($rows[$key])) throw new RuntimeException('That row is no longer on the list. Reload the page.');
    if ($rows[$key]['state'] !== 'open') throw new RuntimeException('Already decided.');
    $v = kop_ms_clean_add($in);
    $pdo = kop_ie_referrer_pdo();
    $dup = $pdo->prepare('SELECT id FROM memorial_victims WHERE name = ? AND COALESCE(program, \'\') = ? AND COALESCE(date_of_death, \'\') = ? LIMIT 1');
    $dup->execute(array($v['name'], $v['program'], $v['date_of_death']));
    if ($id = $dup->fetchColumn()) throw new RuntimeException('The page already has this row (id ' . (int) $id . ').');
    $nul = function ($x) { return $x === '' ? null : $x; };
    $pdo->prepare('INSERT INTO memorial_victims
            (name, age, program, date_of_death, date_precision, cause_of_death, cause_category,
             location, source_name, source_url, notes, publication_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'published\')')->execute(array(
        $v['name'], $v['age'] === '' ? null : (int) $v['age'], $nul($v['program']), $nul($v['date_of_death']), $v['date_precision'],
        $nul($v['cause_of_death']), $nul($v['cause_category']), $nul($v['location']), $nul($v['source_name']), $v['source_url'],
        $nul($v['notes']),
    ));
    $id = (int) $pdo->lastInsertId();
    kop_ms_changed($id);
    kop_ms_log_put($key, array('act' => 'added', 'id' => $id, 'new' => $v));
    return $id;
}

function kop_ms_changed($id) {
    if (function_exists('kop_record_changed')) kop_record_changed('memorial', array((int) $id), array('source' => 'memorial-sheet'));
    do_action('litespeed_purge_url', home_url('/in-loving-memory/'));
}

/** Undo one decision exactly: the values before go back (if nobody changed them since), an added row goes. */
function kop_ms_undo($key) {
    $log = kop_ms_log();
    if (!isset($log[$key])) throw new RuntimeException('Nothing to undo.');
    $e = $log[$key];
    $pdo = kop_ie_referrer_pdo();
    if ($e['act'] === 'added') {
        $q = $pdo->prepare('SELECT * FROM memorial_victims WHERE id = ?');
        $q->execute(array((int) $e['id']));
        if ($q->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare('DELETE FROM memorial_victims WHERE id = ?')->execute(array((int) $e['id']));
            kop_ms_changed((int) $e['id']);
        }
    } elseif (!empty($e['new'])) {
        $q = $pdo->prepare('SELECT * FROM memorial_victims WHERE id = ?');
        $q->execute(array((int) $e['id']));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('The page\'s row is gone.');
        if (!kop_ms_matches($e['new'], $row)) throw new RuntimeException('Changed on the page since; edit it there.');
        $old = array();
        foreach ($e['old'] as $col => $v) $old[$col] = $v === null ? '' : (string) $v;
        kop_ie_row_save(array('memorial', (int) $e['id']), $old, array('source' => 'memorial-sheet-undo'));
    }
    kop_ms_log_put($key, null);
}

add_action('wp_ajax_kop_memorial_sheet', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Not allowed.', 403);
    check_ajax_referer('kop_memorial_sheet', 'nonce');
    $act = sanitize_key($_POST['act'] ?? '');
    $key = preg_replace('/[^a-z0-9:]/', '', strtolower((string) wp_unslash($_POST['key'] ?? '')));
    try {
        switch ($act) {
            case 'settle':
                $msg = kop_ms_settle($key, sanitize_key($_POST['choice'] ?? ''), wp_unslash($_POST['value'] ?? ''), sanitize_key($_POST['precision'] ?? ''));
                wp_send_json_success(array('message' => $msg));
            case 'add':
                $id = kop_ms_add($key, (array) wp_unslash($_POST['fields'] ?? array()));
                wp_send_json_success(array('message' => 'Added to the page (id ' . $id . ').', 'url' => home_url('/in-loving-memory/')));
            case 'skip':
                $rows = kop_ms_add_rows();
                if (!isset($rows[$key]) || $rows[$key]['state'] !== 'open') throw new RuntimeException('That row is no longer waiting.');
                kop_ms_log_put($key, array('act' => 'skipped'));
                wp_send_json_success(array('message' => 'Skipped.'));
            case 'undo':
                kop_ms_undo($key);
                wp_send_json_success(array('message' => 'Undone.'));
        }
        wp_send_json_error('Unknown action.');
    } catch (Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});

/* ---- Screen ------------------------------------------------------------------------------------------ */

function kop_ms_url(array $args = array()) {
    return add_query_arg(array_filter($args + array('page' => 'kop-memorial-sheet'), function ($v) { return $v !== '' && $v !== null; }), admin_url('admin.php'));
}

function kop_ms_link($url, $text = '') {
    $url = (string) $url;
    if ($url === '') return '';
    $label = $text !== '' ? $text : (function_exists('kop_url_label') ? kop_url_label($url) : (wp_parse_url($url, PHP_URL_HOST) ?: 'link'));
    return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
}

function kop_ms_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $seed = kop_ms_seed();
    $tab = sanitize_key($_GET['tab'] ?? 'conflicts');
    $tab = in_array($tab, array('conflicts', 'add', 'page'), true) ? $tab : 'conflicts';
    try {
        $lines = kop_ms_conflict_lines();
        $adds = kop_ms_add_rows();
    } catch (Throwable $e) {
        echo '<div class="wrap kop-ak">' . kop_ak_empty('No database connection: ' . $e->getMessage()) . '</div>';
        return;
    }
    $open_lines = count(array_filter($lines, function ($x) { return $x['state'] === 'open'; }));
    $open_adds = count(array_filter($adds, function ($x) { return $x['state'] === 'open'; }));

    echo '<div class="wrap kop-ak kop-ms">';
    echo kop_ak_header('Memorial sheet', array(
        'lede' => 'In Loving Memory against your "Death Toll" sheet: settle each field where they differ, add the sheet\'s rows the page lacks, and see what only the page has.',
        'steps' => array(
            '<em>Conflicts</em>: one line per field. Pick <em>Sheet</em>, <em>Page</em> or <em>Type</em> (then type the value) on as many lines as you like and press <em>Save this person</em>. Lines marked <em>Check the source</em> need the source read, not a pick by vote.',
            '<em>Add</em>: the sheet\'s rows the page lacks. Fix anything in the boxes, then <em>Add to the page</em> (published at once) or <em>Skip</em>. A row needs a source link to go in; one with none stays out until a source is found.',
            'Every decision has <em>Undo</em> on its result line and under <em>Decided</em>, which puts back exactly what was there.',
        ),
        'help' => '<p>The sheet\'s side is the snapshot of ' . esc_html($seed['snapshot'] ?? '') . ' (the site can\'t read the sheet); the page\'s side is live, so a line the page already agrees with drops off by itself. '
            . 'Saves go through the same save as the page\'s pencil. The cause filter is never changed here, and a restraint death\'s cause has to keep saying restraint.</p>',
    ));
    if (!$seed['conflicts'] && !$seed['add']) {
        echo kop_ak_empty('seeds/memorial-sheet.json is missing or empty. Build it with kop-pipeline scripts/build-memorial-sheet-seed.py and deploy.');
        echo '</div>';
        return;
    }
    echo kop_ak_status('Sheet snapshot ' . esc_html($seed['snapshot'] ?? '') . ' &middot; <strong>' . (int) ($seed['sheet_rows'] ?? 0) . '</strong> sheet rows, <strong>'
        . (int) ($seed['matched'] ?? 0) . '</strong> on both, <strong>' . count(kop_ms_rows()) . '</strong> on the page now');
    echo kop_ak_tabs(array(
        'conflicts' => array('label' => 'Conflicts', 'count' => $open_lines),
        'add' => array('label' => 'Add', 'count' => $open_adds),
        'page' => array('label' => 'Only on the page', 'count' => count($seed['page_only'])),
    ), $tab, function ($k) { return kop_ms_url(array('tab' => $k)); });

    if ($tab === 'conflicts') kop_ms_conflicts_view($lines);
    elseif ($tab === 'add') kop_ms_add_view($adds);
    else kop_ms_page_only_view();
    echo '</div>';
}

function kop_ms_conflicts_view(array $lines) {
    $seg = sanitize_key($_GET['seg'] ?? 'open');
    $seg = in_array($seg, array('open', 'decided', 'agrees'), true) ? $seg : 'open';
    $field = sanitize_key($_GET['field'] ?? '');
    $check = sanitize_key($_GET['check'] ?? '');
    $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
    $paged = max(1, (int) ($_GET['paged'] ?? 1));

    $counts = array('open' => 0, 'decided' => 0, 'agrees' => 0);
    foreach ($lines as $x) if (isset($counts[$x['state']])) $counts[$x['state']]++;
    echo kop_ak_tabs(array(
        'open' => array('label' => 'Waiting', 'count' => $counts['open']),
        'decided' => array('label' => 'Decided', 'count' => $counts['decided']),
        'agrees' => array('label' => 'Page already agrees', 'count' => $counts['agrees']),
    ), $seg, function ($k) use ($field, $check, $q) { return kop_ms_url(array('tab' => 'conflicts', 'seg' => $k, 'field' => $field, 'check' => $check, 'q' => $q)); }, array('kind' => 'segments'));

    $fields = kop_ms_fields();
    echo kop_ak_filters(array('page' => 'kop-memorial-sheet', 'tab' => 'conflicts', 'seg' => $seg), array(
        array('type' => 'search', 'name' => 'q', 'value' => $q, 'placeholder' => 'Find a name or program', 'label' => 'Find'),
        array('type' => 'select', 'name' => 'field', 'value' => $field, 'label' => 'Field', 'all' => 'Every field',
            'options' => array_map(function ($f) { return $f[0]; }, $fields)),
        array('type' => 'select', 'name' => 'check', 'value' => $check, 'label' => 'Check', 'all' => 'Every line',
            'options' => array('yes' => 'Check the source', 'no' => 'The rest')),
    ), kop_ms_url(array('tab' => 'conflicts', 'seg' => $seg)));

    // People with at least one line in this view, in the seed's order.
    $people = array();
    foreach ($lines as $x) {
        if ($x['state'] !== $seg) continue;
        if ($field !== '' && $x['l']['field'] !== $field) continue;
        if ($check === 'yes' && empty($x['l']['check'])) continue;
        if ($check === 'no' && !empty($x['l']['check'])) continue;
        if ($q !== '') {
            $hay = $x['c']['name'] . ' ' . ($x['row']['name'] ?? '') . ' ' . ($x['row']['program'] ?? '');
            if (stripos($hay, $q) === false) continue;
        }
        $people[$x['c']['row']][] = $x;
    }
    if (!$people) {
        echo kop_ak_empty($seg === 'open' ? 'Nothing waiting here.' : 'Nothing here yet.');
        return;
    }
    $total = count($people);
    $pages = (int) ceil($total / KOP_MS_PER_PAGE);
    $paged = min($paged, $pages);
    foreach (array_slice($people, ($paged - 1) * KOP_MS_PER_PAGE, KOP_MS_PER_PAGE, true) as $plines) {
        echo kop_ms_person_card($plines, $seg);
    }
    echo kop_ak_pager($paged, $pages, $total, 'people', function ($n) use ($seg, $field, $check, $q) {
        return kop_ms_url(array('tab' => 'conflicts', 'seg' => $seg, 'field' => $field, 'check' => $check, 'q' => $q, 'paged' => $n));
    });
}

function kop_ms_person_card(array $plines, $seg) {
    $c = $plines[0]['c'];
    $row = $plines[0]['row'];
    $fields = kop_ms_fields();
    $title = esc_html($c['name']) . ($row && $row['name'] !== $c['name'] ? ' <span class="kop-ak-muted">(page: ' . esc_html($row['name']) . ')</span>' : '');
    $sub = '<div>Sheet row ' . (int) $c['row'] . ' &middot; page id ' . (int) $c['id']
        . ($row ? ' &middot; ' . esc_html(trim(($row['program'] ?? '') . ', ' . kop_ms_date_label($row['date_of_death'], $row['date_precision']), ', ')) : '') . '</div>'
        . '<div>Sources: sheet ' . ($c['source'] !== '' ? kop_ms_link($c['source']) : '<em>none</em>')
        . ' &middot; page ' . ($row && $row['source_url'] ? kop_ms_link($row['source_url']) : '<em>none</em>') . '</div>'
        . ($c['note'] !== '' ? '<div>Sheet note: ' . esc_html($c['note']) . '</div>' : '')
        . ($row && kop_ms_is_restraint($row) ? '<div class="kop-ak-warn">Restraint death: the filter stays Restraint, and a cause that says restraint keeps saying it.</div>' : '');
    $out = '<section class="kop-ak-card kop-ms-card" data-person="' . (int) $c['row'] . '">' . kop_ak_card_head($title, $sub, false);
    $out .= '<table class="widefat kop-ms-lines"><thead><tr><th>Field</th><th>Sheet</th><th>Page now</th><th>' . ($seg === 'open' ? 'Keep' : 'Decision') . '</th></tr></thead><tbody>';
    foreach ($plines as $x) {
        $l = $x['l'];
        $f = $l['field'];
        $page_val = $row ? kop_ms_page_value($f, $row) : '';
        $sheet_val = $f === 'source' ? kop_ms_link($l['sheet']) : esc_html($l['sheet']);
        if (isset($l['set']['location']) && $l['set']['location'] !== $l['sheet']) {
            $sheet_val .= '<div class="kop-ak-muted">Writes: ' . esc_html($l['set']['location']) . '</div>';
        }
        if ($f === 'date') {
            $sheet_val .= '<div class="kop-ak-muted">Writes: ' . esc_html($l['set']['date_of_death'] . ' (' . $l['set']['date_precision'] . ')') . '</div>';
        }
        $notes = (!empty($l['check']) ? '<span class="kop-ak-tag kop-ms-check">Check the source</span> <span class="kop-ak-muted">' . esc_html($l['check']) . '</span>' : '')
            . (!empty($l['hint']) ? '<div class="kop-ak-muted">' . esc_html($l['hint']) . '</div>' : '')
            . (!empty($l['fill']) ? '<div class="kop-ak-muted">The page has none.</div>' : '');
        $out .= '<tr data-key="' . esc_attr($x['key']) . '" data-field="' . esc_attr($f) . '">'
            . '<th scope="row">' . esc_html($fields[$f][0]) . ($notes !== '' ? '<div class="kop-ms-notes">' . $notes . '</div>' : '') . '</th>'
            . '<td class="kop-ms-val" data-label="Sheet">' . $sheet_val . '</td>'
            . '<td class="kop-ms-val" data-label="Page now">' . ($f === 'source' ? kop_ms_link($page_val) : esc_html($page_val)) . '</td><td class="kop-ms-pick">';
        if ($seg === 'open') {
            $n = 'pick-' . esc_attr(str_replace(':', '-', $x['key']));
            $out .= '<label><input type="radio" name="' . $n . '" value="sheet"> Sheet</label> '
                . '<label><input type="radio" name="' . $n . '" value="page"> Page</label> '
                . '<label><input type="radio" name="' . $n . '" value="typed"> Type</label>'
                . '<div class="kop-ms-typed" hidden>';
            $label = 'Value for ' . $fields[$f][0];
            if ($f === 'cause') {
                $out .= '<textarea rows="3" class="kop-ms-value" aria-label="' . esc_attr($label) . '">' . esc_textarea($row['cause_of_death'] ?? '') . '</textarea>';
            } elseif ($f === 'date') {
                $out .= '<input type="text" class="kop-ms-value" placeholder="YYYY-MM-DD" aria-label="' . esc_attr($label) . '" value="' . esc_attr($row['date_of_death'] ?? '') . '">'
                    . ' <select class="kop-ms-precision" aria-label="How much of the date is known">';
                foreach (array('day', 'month', 'year', 'unknown') as $p) {
                    $out .= '<option value="' . $p . '"' . selected($row['date_precision'] ?? 'day', $p, false) . '>' . esc_html(ucfirst($p)) . '</option>';
                }
                $out .= '</select>';
            } else {
                $col = $fields[$f][1][0];
                $out .= '<input type="text" class="kop-ms-value" aria-label="' . esc_attr($label) . '" value="' . esc_attr($row[$col] ?? '') . '">';
            }
            $out .= '</div>';
        } elseif ($seg === 'decided') {
            $e = $x['log'];
            $what = array('page' => 'Kept the page\'s', 'sheet' => 'Took the sheet\'s', 'typed' => 'Typed a value');
            $out .= esc_html($what[$e['act']] ?? $e['act']) . ' <span class="kop-ak-muted">' . esc_html($e['at'] ?? '') . '</span> '
                . kop_ak_button('Undo', 'quiet', 'data-ms-act="undo" data-key="' . esc_attr($x['key']) . '"')
                . '<div class="kop-ak-result" aria-live="polite"></div>';
        } else {
            $out .= '<span class="kop-ak-muted">The page has the sheet\'s value.</span>';
        }
        $out .= '</td></tr>';
    }
    $out .= '</tbody></table>';
    if ($seg === 'open') {
        $out .= kop_ak_card_actions(kop_ak_button('Save this person', 'primary', 'data-ms-act="settle"'));
    }
    return $out . '</section>';
}

function kop_ms_add_view(array $adds) {
    $seg = sanitize_key($_GET['seg'] ?? 'open');
    $segs = array('open' => 'Waiting', 'added' => 'Added', 'skipped' => 'Skipped', 'seeded' => 'Added from the seed file');
    $seg = isset($segs[$seg]) ? $seg : 'open';
    $kind = sanitize_key($_GET['kind'] ?? '');
    $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $counts = array_fill_keys(array_keys($segs), 0);
    foreach ($adds as $x) if (isset($counts[$x['state']])) $counts[$x['state']]++;
    $tabs = array();
    foreach ($segs as $k => $label) $tabs[$k] = array('label' => $label, 'count' => $counts[$k]);
    echo kop_ak_tabs($tabs, $seg, function ($k) use ($kind, $q) { return kop_ms_url(array('tab' => 'add', 'seg' => $k, 'kind' => $kind, 'q' => $q)); }, array('kind' => 'segments'));
    echo kop_ak_filters(array('page' => 'kop-memorial-sheet', 'tab' => 'add', 'seg' => $seg), array(
        array('type' => 'search', 'name' => 'q', 'value' => $q, 'placeholder' => 'Find a name or program', 'label' => 'Find'),
        array('type' => 'select', 'name' => 'kind', 'value' => $kind, 'label' => 'Rows', 'all' => 'Every row', 'options' => array(
            'source' => 'With a source', 'nosource' => 'No source yet', 'from1971' => '1971 on', 'before1971' => 'Before 1971', 'nodate' => 'No date', 'flagged' => 'With a note to check')),
    ), kop_ms_url(array('tab' => 'add', 'seg' => $seg)));

    $list = array_filter($adds, function ($x) use ($seg, $kind, $q) {
        $a = $x['a'];
        if ($x['state'] !== $seg) return false;
        $d = (string) ($a['date_of_death'] ?? '');
        if ($kind === 'source' && $a['source_url'] === '') return false;
        if ($kind === 'nosource' && $a['source_url'] !== '') return false;
        if ($kind === 'from1971' && ($d === '' || $d < '1971')) return false;
        if ($kind === 'before1971' && ($d === '' || $d >= '1971')) return false;
        if ($kind === 'nodate' && $d !== '') return false;
        if ($kind === 'flagged' && !$a['flags'] && !$x['maybe']) return false;
        return $q === '' || stripos($a['name'] . ' ' . $a['program'], $q) !== false;
    });
    if (!$list) {
        echo kop_ak_empty($seg === 'open' ? 'Nothing waiting here.' : 'Nothing here yet.');
        return;
    }
    $total = count($list);
    $pages = (int) ceil($total / KOP_MS_PER_PAGE);
    $paged = min($paged, $pages);
    $causes = kop_memorial_causes('offered');
    foreach (array_slice($list, ($paged - 1) * KOP_MS_PER_PAGE, KOP_MS_PER_PAGE, true) as $x) {
        echo kop_ms_add_card($x, $causes);
    }
    echo kop_ak_pager($paged, $pages, $total, 'rows', function ($n) use ($seg, $kind, $q) {
        return kop_ms_url(array('tab' => 'add', 'seg' => $seg, 'kind' => $kind, 'q' => $q, 'paged' => $n));
    });
}

/** A first guess at the cause filter from the sheet's words (restraint first: it is never relabelled). */
function kop_ms_guess_cause($text) {
    $map = array(
        'restraint' => '/restrain|held down|prone|pinned|bivy|sat on/i',
        'suicide' => '/suicid|hang|hanged|took (his|her|their) own life/i',
        'overdose' => '/overdose|fentanyl|medication|drug/i',
        'drowning' => '/drown/i',
        'exposure' => '/heat|hypotherm|dehydrat|exposure|heatstroke/i',
        'escape_attempt' => '/escap|ran away|runaway|fled/i',
        'violence' => '/beat|murder|homicide|stabb|shot|assault|killed by/i',
        'medical_neglect' => '/neglect|untreated|denied|ignored|failed medical/i',
        'accident' => '/accident|crash|struck|fell|fall|fire/i',
    );
    foreach ($map as $k => $re) {
        if (preg_match($re, (string) $text)) return $k;
    }
    return trim((string) $text) === '' || stripos((string) $text, 'unknown') !== false ? 'unknown' : 'other';
}

function kop_ms_add_card(array $x, array $causes) {
    $a = $x['a'];
    $title = esc_html($a['name']) . ' <span class="kop-ak-muted">sheet row ' . (int) $a['row'] . '</span>';
    $sub = '<div>' . implode(' &middot; ', array_map('esc_html', array_filter(array($a['program'], $a['location_raw'], $a['date_raw'] !== '' ? $a['date_raw'] : 'no date')))) . '</div>'
        . '<div>Source: ' . ($a['source_url'] !== '' ? kop_ms_link($a['source_url']) : '<em>none on the sheet</em>') . '</div>'
        . ($a['note'] !== '' ? '<div>Sheet note: ' . esc_html($a['note']) . '</div>' : '');
    foreach ($a['flags'] as $f) $sub .= '<div class="kop-ak-warn">' . esc_html($f) . '</div>';
    if ($x['maybe']) {
        $sub .= '<div class="kop-ak-warn">The page has someone of this name: id ' . esc_html(implode(', ', $x['maybe'])) . '</div>';
    }
    $out = '<section class="kop-ak-card kop-ms-card kop-ms-add" data-key="' . esc_attr($x['key']) . '">' . kop_ak_card_head($title, $sub, false);
    if ($x['state'] !== 'open') {
        $e = $x['log'];
        $done = $x['state'] === 'seeded' ? 'Added from seeds/memorial.json.'
            : ($x['state'] === 'added' ? 'Added as id ' . (int) $e['id'] . ' ' : 'Skipped ') . '<span class="kop-ak-muted">' . esc_html($e['at'] ?? '') . '</span>';
        $btn = $x['state'] === 'seeded' ? '' : kop_ak_button('Undo', 'quiet', 'data-ms-act="undo" data-key="' . esc_attr($x['key']) . '"');
        return $out . kop_ak_card_actions('<span>' . $done . '</span>' . $btn) . '</section>';
    }
    $input = function ($name, $label, $value, $type = 'text', $wide = false) {
        return '<label class="kop-ms-field' . ($wide ? ' is-wide' : '') . '"><span>' . esc_html($label) . '</span>'
            . ($type === 'textarea' ? '<textarea name="' . esc_attr($name) . '" rows="2">' . esc_textarea((string) $value) . '</textarea>'
                : '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">') . '</label>';
    };
    $guess = kop_ms_guess_cause($a['cause']);
    $cat = '<label class="kop-ms-field"><span>Cause, for the filter</span><select name="cause_category">';
    foreach ($causes as $k => $l) $cat .= '<option value="' . esc_attr($k) . '"' . selected($guess, $k, false) . '>' . esc_html($l) . '</option>';
    $cat .= '</select></label>';
    $prec = '<label class="kop-ms-field"><span>How much of the date is known</span><select name="date_precision">';
    foreach (array('day', 'month', 'year', 'unknown') as $p) $prec .= '<option value="' . $p . '"' . selected($a['date_precision'], $p, false) . '>' . esc_html(ucfirst($p)) . '</option>';
    $prec .= '</select></label>';
    $host = $a['source_url'] !== '' ? (string) wp_parse_url($a['source_url'], PHP_URL_HOST) : '';
    $out .= '<div class="kop-ms-form">'
        . $input('name', 'Name ("Unknown" when not released)', $a['name'])
        . $input('age', 'Age', $a['age'] === null ? '' : $a['age'])
        . $input('program', 'Program', $a['program'])
        . $input('location', 'State, or region outside the US', $a['location'])
        . $input('date_of_death', 'Date of death (YYYY-MM-DD)', $a['date_of_death'] ?? '')
        . $prec
        . $input('cause_of_death', 'Cause, as reported', $a['cause'], 'textarea', true)
        . $cat
        . $input('source_url', 'Source link (needed)', $a['source_url'], 'text', true)
        . $input('source_name', 'Source name', preg_replace('/^www\./', '', $host))
        . $input('notes', 'Internal notes (not shown)', 'From the Death Toll sheet, row ' . (int) $a['row'] . ($a['note'] !== '' ? '. Sheet note: ' . $a['note'] : '') . '.', 'textarea', true)
        . '</div>';
    return $out . kop_ak_card_actions(kop_ak_button('Add to the page', 'primary', 'data-ms-act="add"') . kop_ak_button('Skip', 'danger', 'data-ms-act="skip"')) . '</section>';
}

function kop_ms_page_only_view() {
    $seed = kop_ms_seed();
    $rows = kop_ms_rows();
    echo '<p class="kop-ak-muted">On the page, not on the sheet (add them to the sheet if it stays in use). For the record: nothing to decide here.</p>';
    echo '<table class="widefat striped kop-ms-only"><thead><tr><th>Id</th><th>Name</th><th>Program</th><th>Date</th><th>Where</th><th>Cause</th><th>Source</th></tr></thead><tbody>';
    foreach ($seed['page_only'] as $id) {
        $r = $rows[(int) $id] ?? null;
        if (!$r) {
            echo '<tr><td>' . (int) $id . '</td><td colspan="6"><em>No longer on the page.</em></td></tr>';
            continue;
        }
        $note = $seed['page_only_notes'][(string) $id] ?? '';
        echo '<tr><td>' . (int) $id . '</td><td>' . esc_html($r['name']) . ($note !== '' ? '<div class="kop-ak-warn">' . esc_html($note) . '</div>' : '') . '</td>'
            . '<td>' . esc_html((string) $r['program']) . '</td><td>' . esc_html(kop_ms_date_label($r['date_of_death'], $r['date_precision'])) . '</td>'
            . '<td>' . esc_html((string) $r['location']) . '</td><td>' . esc_html((string) $r['cause_of_death']) . '</td>'
            . '<td>' . kop_ms_link((string) $r['source_url'], (string) ($r['source_name'] ?: '')) . '</td></tr>';
    }
    echo '</tbody></table>';
}
