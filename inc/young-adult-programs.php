<?php
/**
 * Young adult programs: residential, wilderness, transitional living,
 * college support and treatment programs for people 18 and older, kept
 * apart from the troubled teen industry's facility records.
 *
 * They are not in facilities_v2, so they appear on no facility page,
 * directory, hub, map, search or open-data download. They are listed on
 * /young-adult-programs/ (templates/page-young-adult-programs.php) and
 * managed at KOP Tools > Young Adult Programs (inc/young-adult-programs-admin.php).
 *
 * Most come from Woodbury Reports: the "Young adult programs (18+)" tab of
 * KOP Tools > Woodbury Facts creates a record here, or adds to one, and the
 * checked items become its facts, each citing its issue page. A fact can
 * also fill an empty field (opened, closed, ages, other names, run by,
 * described as); Undo on Woodbury Facts takes back exactly that.
 *
 * Table, in the records database (kop_seed_pdo(), where news_submissions is):
 *   young_adult_programs  one row per program; review = 'approved' (listed)
 *                         or 'pending'; facts = JSON list of
 *                         {key, group, label, cites: [{label, number, page, url}], by, at};
 *                         document_folder_id = its FileBird folder, made the
 *                         first time something is filed under it
 *                         (kop_wbc_existing_target('ya', id), under "Young
 *                         Adult Programs"), or the facility-era folder of a
 *                         program moved out of the facility records
 *
 * Notes are written in the page-text format (inc/page-text.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_YA_DB_VERSION', '2');
define('KOP_YA_PAGE', 'young-adult-programs');
// The media library folder each program's own folder is made under.
define('KOP_YA_FOLDER_PARENT', 'Young Adult Programs');

function kop_ya_pdo() {
    return function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
}

/** The table, created once per version (MySQL). */
function kop_ya_install(PDO $pdo) {
    if (get_option('kop_ya_db') === KOP_YA_DB_VERSION) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS young_adult_programs (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        name_key VARCHAR(191) NOT NULL,
        other_names TEXT NULL,
        city VARCHAR(100) NOT NULL DEFAULT '',
        state VARCHAR(100) NOT NULL DEFAULT '',
        country VARCHAR(100) NOT NULL DEFAULT '',
        ages VARCHAR(100) NOT NULL DEFAULT '',
        program_type VARCHAR(255) NOT NULL DEFAULT '',
        run_by VARCHAR(255) NOT NULL DEFAULT '',
        opened SMALLINT NULL,
        closed SMALLINT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Unknown',
        notes TEXT NULL,
        links TEXT NULL,
        facts LONGTEXT NULL,
        review VARCHAR(20) NOT NULL DEFAULT 'approved',
        source VARCHAR(255) NOT NULL DEFAULT '',
        created_by VARCHAR(100) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        document_folder_id BIGINT UNSIGNED NULL,
        PRIMARY KEY (id),
        UNIQUE KEY name_key (name_key),
        KEY review (review)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Version 2: the document folder, on a table made before it.
    if (!$pdo->query("SHOW COLUMNS FROM young_adult_programs LIKE 'document_folder_id'")->fetch()) {
        $pdo->exec('ALTER TABLE young_adult_programs ADD COLUMN document_folder_id BIGINT UNSIGNED NULL');
    }
    update_option('kop_ya_db', KOP_YA_DB_VERSION);
}

/** The program's card on /young-adult-programs/. */
function kop_ya_url($id) {
    return home_url('/' . KOP_YA_PAGE . '/#kop-ya-program-' . (int) $id);
}

function kop_ya_ready(PDO $pdo) {
    try {
        $pdo->query('SELECT 1 FROM young_adult_programs LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function kop_ya_now() {
    return gmdate('Y-m-d H:i:s');
}

function kop_ya_clean_name($name) {
    $name = str_replace(array("\u{2019}", "\u{2018}", "\u{FFFD}"), "'", (string) $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}

/** The comparison form of a name: lower case, letters and digits only. */
function kop_ya_key($name) {
    $name = strtolower(str_replace("'", '', kop_ya_clean_name($name)));
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $name));
}

/** The initials of a name's words, small words left out: "Optimum Performance Institute" -> "opi". */
function kop_ya_initials($name) {
    $words = array_diff(explode(' ', kop_ya_key($name)), array('', 'the', 'of', 'and', 'at', 'for', 'a', 'to', 'in'));
    return implode('', array_map(function ($w) { return $w[0]; }, $words));
}

/**
 * The forms a name may be written in to find its program: as written, without
 * a bracketed part, and the bracketed part alone ("Optimum Performance
 * Institute (OPI)" gives all three).
 */
function kop_ya_name_forms($name) {
    $name = kop_ya_clean_name($name);
    $out = array($name);
    if (preg_match('/^(.*?)\s*\(([^)]*)\)\s*(.*)$/', $name, $m)) {
        $out[] = trim($m[1] . ' ' . $m[3]);
        $out[] = trim($m[2]);
    }
    return array_values(array_unique(array_filter($out, 'strlen')));
}

/** Is $name (one word of 3 to 6 letters, "OPI") the initials of $p's name? */
function kop_ya_is_initials_of($name, array $p) {
    $k = kop_ya_key($name);
    return (bool) preg_match('/^[a-z]{3,6}$/', $k) && strlen(kop_ya_initials($p['name'])) >= 3 && kop_ya_initials($p['name']) === $k;
}

function kop_ya_lines($text) {
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), 'strlen'));
}

/** "Label | https://..." lines as array(label, url), bad addresses dropped. */
function kop_ya_parse_links($text) {
    $out = array();
    foreach (kop_ya_lines($text) as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        $url = count($parts) === 2 ? $parts[1] : $parts[0];
        $label = count($parts) === 2 ? $parts[0] : '';
        if (preg_match('#^https?://[^\s<>"\']+$#i', $url)) {
            $out[] = array($label !== '' ? $label : preg_replace('#^https?://(www\.)?#i', '', $url), $url);
        }
    }
    return $out;
}

/* ---- Reading --------------------------------------------------------- */

/** Every program, by name. $review: 'approved', 'pending' or null for all. */
function kop_ya_all(PDO $pdo, $review = 'approved') {
    if (!kop_ya_ready($pdo)) {
        return array();
    }
    if ($review === null) {
        return $pdo->query('SELECT * FROM young_adult_programs ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare('SELECT * FROM young_adult_programs WHERE review = ? ORDER BY name');
    $stmt->execute(array($review));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kop_ya_get(PDO $pdo, $id) {
    $stmt = $pdo->prepare('SELECT * FROM young_adult_programs WHERE id = ?');
    $stmt->execute(array((int) $id));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * The program a name belongs to (its name or one of its other names, also
 * with a bracketed part left off or alone), or null. Initials ("OPI") count
 * when they belong to one program only.
 */
function kop_ya_find_by_name(PDO $pdo, $name) {
    if (kop_ya_key($name) === '' || !kop_ya_ready($pdo)) {
        return null;
    }
    $all = kop_ya_all($pdo, null);
    $forms = kop_ya_name_forms($name);
    foreach ($forms as $form) {
        $key = kop_ya_key($form);
        foreach ($all as $p) {
            if ($p['name_key'] === $key) {
                return $p;
            }
            foreach (kop_ya_lines($p['other_names']) as $other) {
                if (kop_ya_key($other) === $key) {
                    return $p;
                }
            }
        }
    }
    foreach ($forms as $form) {
        $hits = array_values(array_filter($all, function ($p) use ($form) { return kop_ya_is_initials_of($form, $p); }));
        if (count($hits) === 1) {
            return $hits[0];
        }
    }
    return null;
}

function kop_ya_facts(array $p) {
    $f = json_decode((string) ($p['facts'] ?? ''), true);
    return is_array($f) ? $f : array();
}

/* ---- Writing --------------------------------------------------------- */

/** A US state as its two-letter code; anything else as given. */
function kop_ya_state($s) {
    $s = trim((string) $s);
    if ($s === '' || !function_exists('kop_facility_state_code')) {
        return $s;
    }
    $code = (string) kop_facility_state_code($s);
    return $code !== '' ? $code : $s;
}

/**
 * Save a program from fields (name, other_names, city, state, country, ages,
 * program_type, run_by, opened, closed, status, notes, links, review,
 * source). Returns the id. Throws when the name is empty or taken.
 */
function kop_ya_save(PDO $pdo, array $f, $id = 0, $by = '') {
    $name = kop_ya_clean_name($f['name'] ?? '');
    if ($name === '') {
        throw new RuntimeException('A program needs a name.');
    }
    $key = kop_ya_key($name);
    $same = $pdo->prepare('SELECT name FROM young_adult_programs WHERE name_key = ? AND id <> ?');
    $same->execute(array($key, (int) $id));
    if ($taken = $same->fetchColumn()) {
        throw new RuntimeException('There is already a young adult program called "' . $taken . '".');
    }
    $year = static function ($v) {
        $y = is_numeric($v) ? (int) $v : 0;
        return ($y >= 1850 && $y <= (int) gmdate('Y') + 1) ? $y : null;
    };
    $str = static function ($v, $max) {
        return mb_substr(trim(preg_replace('/[ \t]+/', ' ', (string) $v)), 0, $max);
    };
    $row = array(
        'name'         => mb_substr($name, 0, 255),
        'name_key'     => mb_substr($key, 0, 191),
        'other_names'  => implode("\n", array_map('kop_ya_clean_name', kop_ya_lines($f['other_names'] ?? ''))),
        'city'         => $str($f['city'] ?? '', 100),
        'state'        => $str(kop_ya_state($f['state'] ?? ''), 100),
        'country'      => $str($f['country'] ?? '', 100),
        'ages'         => $str($f['ages'] ?? '', 100),
        'program_type' => $str($f['program_type'] ?? '', 255),
        'run_by'       => $str($f['run_by'] ?? '', 255),
        'opened'       => $year($f['opened'] ?? null),
        'closed'       => $year($f['closed'] ?? null),
        'status'       => in_array($f['status'] ?? '', array('Open', 'Closed'), true) ? $f['status'] : 'Unknown',
        'notes'        => function_exists('kop_page_text_clean') ? kop_page_text_clean($f['notes'] ?? '') : trim((string) ($f['notes'] ?? '')),
        'links'        => implode("\n", kop_ya_lines($f['links'] ?? '')),
        'updated_at'   => kop_ya_now(),
    );
    if (isset($f['review'])) {
        $row['review'] = $f['review'] === 'pending' ? 'pending' : 'approved';
    }
    if ($id) {
        $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($row)));
        $pdo->prepare("UPDATE young_adult_programs SET $sets WHERE id = ?")->execute(array_merge(array_values($row), array((int) $id)));
        return (int) $id;
    }
    $row['review'] = $row['review'] ?? 'approved';
    $row['facts'] = '[]';
    $row['source'] = mb_substr((string) ($f['source'] ?? ''), 0, 255);
    $row['created_by'] = mb_substr((string) $by, 0, 100);
    $row['created_at'] = $row['updated_at'];
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO young_adult_programs (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));
    return (int) $pdo->lastInsertId();
}

function kop_ya_delete(PDO $pdo, $id) {
    $pdo->prepare('DELETE FROM young_adult_programs WHERE id = ?')->execute(array((int) $id));
}

/** The fields a fact may fill when they are empty; other_names takes a new line instead. */
function kop_ya_fillable() {
    return array('opened', 'closed', 'status', 'ages', 'program_type', 'run_by', 'other_names');
}

/**
 * Add a fact to a program, and fill the fields in $fill (column => value)
 * that are empty (other_names: add the line if it is not there). Returns
 * what was done, for kop_ya_remove_fact(). Throws when the program already
 * has a fact with this key.
 */
function kop_ya_add_fact(PDO $pdo, $id, array $fact, array $fill = array()) {
    $p = kop_ya_get($pdo, $id);
    if (!$p) {
        throw new RuntimeException('There is no young adult program ' . (int) $id . '.');
    }
    $facts = kop_ya_facts($p);
    foreach ($facts as $f) {
        if (($f['key'] ?? '') === $fact['key']) {
            throw new RuntimeException('Already on ' . $p['name'] . '.');
        }
    }
    $facts[] = $fact;
    $done = array('id' => (int) $id, 'key' => $fact['key'], 'filled' => array());
    $set = array('facts' => wp_json_encode($facts), 'updated_at' => kop_ya_now());
    foreach ($fill as $col => $value) {
        if (!in_array($col, kop_ya_fillable(), true) || $value === null || $value === '') {
            continue;
        }
        if ($col === 'other_names') {
            $lines = kop_ya_lines($p['other_names']);
            if (!in_array(kop_ya_key($value), array_map('kop_ya_key', array_merge($lines, array($p['name']))), true)) {
                $set['other_names'] = implode("\n", array_merge($lines, array($value)));
                $done['filled']['other_names'] = array('line' => $value);
            }
            continue;
        }
        $empty = $col === 'status' ? $p['status'] === 'Unknown' : ($p[$col] === null || $p[$col] === '');
        if ($empty) {
            $set[$col] = $value;
            $done['filled'][$col] = array('before' => $p[$col], 'value' => $value);
        }
    }
    $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($set)));
    $pdo->prepare("UPDATE young_adult_programs SET $sets WHERE id = ?")->execute(array_merge(array_values($set), array((int) $id)));
    return $done;
}

/** Take back what kop_ya_add_fact() did, leaving any field edited since. */
function kop_ya_remove_fact(PDO $pdo, array $done) {
    $p = kop_ya_get($pdo, $done['id'] ?? 0);
    if (!$p) {
        return;
    }
    $facts = array_values(array_filter(kop_ya_facts($p), function ($f) use ($done) { return ($f['key'] ?? '') !== $done['key']; }));
    $set = array('facts' => wp_json_encode($facts), 'updated_at' => kop_ya_now());
    foreach ((array) ($done['filled'] ?? array()) as $col => $d) {
        if ($col === 'other_names') {
            $set['other_names'] = implode("\n", array_values(array_filter(kop_ya_lines($p['other_names']), function ($l) use ($d) {
                return $l !== $d['line'];
            })));
        } elseif (in_array($col, kop_ya_fillable(), true) && (string) $p[$col] === (string) $d['value']) {
            $set[$col] = $d['before'];
        }
    }
    $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($set)));
    $pdo->prepare("UPDATE young_adult_programs SET $sets WHERE id = ?")->execute(array_merge(array_values($set), array((int) $done['id'])));
}

/** Take one fact off by its key (the admin screen). Returns the fact, or null. */
function kop_ya_drop_fact(PDO $pdo, $id, $key) {
    $p = kop_ya_get($pdo, $id);
    if (!$p) {
        return null;
    }
    $gone = null;
    $keep = array();
    foreach (kop_ya_facts($p) as $f) {
        if (($f['key'] ?? '') === $key && $gone === null) {
            $gone = $f;
        } else {
            $keep[] = $f;
        }
    }
    $pdo->prepare('UPDATE young_adult_programs SET facts = ?, updated_at = ? WHERE id = ?')
        ->execute(array(wp_json_encode($keep), kop_ya_now(), (int) $id));
    return $gone;
}

/* ---- Moving a facility record here ------------------------------------ */

/**
 * "Operator: InnerChange (Woodbury Reports, July 2009, p. 7: https://...)" ->
 * a fact citing that page; any other note line -> null.
 */
function kop_ya_fact_from_note($line, $key) {
    if (!preg_match('/^(.*\S)\s*\(Woodbury Reports, ([^()]+?)(?: \((#[^()]+)\))?, p\. (\d+)(?:: (https?:\S+))?\)$/', trim((string) $line), $m)) {
        return null;
    }
    $group = preg_match('/^(Operator|Owner|Past name|Renamed|Opened|Closed|Moved|Former location|Start year|Founded)/i', $m[1]) ? 'history' : 'details';
    return array('key' => $key, 'group' => $group, 'label' => $m[1],
        'cites' => array(array('label' => $m[2], 'number' => $m[3] ?? '', 'page' => (int) $m[4], 'url' => $m[5] ?? '')));
}

/** A staff entry's words: "Name, Role. Earlier: past jobs". */
function kop_ya_staff_label(array $s) {
    $role = trim(preg_replace('/,? (?:Woodbury Reports|HEAL|r\/troubledteens wiki)\)/', ')', (string) ($s['role'] ?? '')));
    $label = trim((string) ($s['name'] ?? '')) . ($role !== '' ? ', ' . $role : '');
    return $label . (trim((string) ($s['pastJobs'] ?? '')) !== '' ? '. Earlier: ' . trim($s['pastJobs']) : '');
}

/**
 * Move a facility record that is a young adult program here: a program made
 * from its name, place, ages, years, operator, staff, history and notes (or
 * added to the program of that name), and the facility row, hub placements,
 * identity and address rows taken out of the facility tables, as
 * kop_ischools_move_facility() does. Woodbury Facts items added to it follow
 * it: a person keeps its item's key (Undo takes them off again) and waiting
 * items move to the "Young adult programs (18+)" tab. Refuses a record with a
 * lawsuit or an article linked (a deleted article does not count; its link
 * goes). Returns the program id.
 *
 * $o: 'into' => a program id: add to that program though its name differs
 * (a second record of the same program; the record's name becomes one of its
 * other names). 'articles' => 'links': an article linked to the record becomes
 * a line under the program's "More about this program" instead of refusing,
 * and its link to the record goes (the article stays published).
 */
function kop_ya_move_facility(PDO $pdo, $facility_id, $by = '', array $o = array()) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    $fid = (int) $facility_id;
    $prefix = isset($wpdb->prefix) ? $wpdb->prefix : 'wpdl_';
    $stored = kop_facility_load($fid, array('pdo' => $pdo, 'prefix' => $prefix));
    if (!$stored) {
        throw new RuntimeException('There is no facility record ' . $fid . '.');
    }
    $into = (int) ($o['into'] ?? 0);
    if ($into && !kop_ya_get($pdo, $into)) {
        throw new RuntimeException('There is no young adult program ' . $into . '.');
    }
    $n = 0;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM lawsuit_facility_links WHERE facility_id = ?');
        $stmt->execute(array($fid));
        $n = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // No such table on this install.
    }
    if ($n > 0) {
        throw new RuntimeException('A lawsuit is linked to facility record ' . $fid . '; it stays a facility until that is sorted out by hand.');
    }
    $articles = array();
    try {
        $stmt = $pdo->prepare("SELECT n.id, n.article_title, n.publication_name, n.publication_date, n.article_url
            FROM news_facility_links l JOIN news_submissions n ON n.id = l.news_id WHERE l.facility_id = ? AND n.status <> 'deleted'");
        $stmt->execute(array($fid));
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // No news tables on this install.
    }
    if ($articles && ($o['articles'] ?? '') !== 'links') {
        throw new RuntimeException('An article is linked to facility record ' . $fid . '; it stays a facility until that is sorted out by hand.');
    }
    $article_lines = array();
    foreach ($articles as $a) {
        if (preg_match('#^https?://#i', (string) $a['article_url'])) {
            $when = substr((string) $a['publication_date'], 0, 4);
            $article_lines[] = trim((string) $a['article_title']) . ' (' . implode(', ', array_filter(array(trim((string) $a['publication_name']), $when))) . ') | ' . trim($a['article_url']);
        }
    }
    $doc = $stored['doc'];
    $id = $doc['identification'] ?? array();
    $loc = $doc['location'] ?? array();
    $period = $doc['operatingPeriod'] ?? array();
    $det = $doc['facilityDetails'] ?? array();
    $name = kop_ya_clean_name(($id['name'] ?? '') ?: ($stored['unique_name'] ?? ''));

    // Woodbury Facts items added to this record: a person keeps its item's key.
    $wbf = array();
    $wbf_table = function_exists('kop_wbf_table') ? kop_wbf_table() : '';
    if ($wbf_table !== '') {
        $wbf = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wbf_table} WHERE status = 'applied' AND applied_fid = %d", $fid), ARRAY_A);
    }
    $person_items = array();
    foreach ($wbf as $r) {
        if ($r['op'] === 'add_staff') {
            $v = json_decode((string) $r['value'], true);
            $person_items[kop_wbf_person_key($v['name'] ?? '')] = $r;
        }
    }

    $facts = array();
    $n = 0;
    foreach (array('administrator', 'notableStaff') as $list) {
        foreach ((array) ($doc['staff'][$list] ?? array()) as $s) {
            $s = is_array($s) ? $s : array('name' => (string) $s);
            if (trim((string) ($s['name'] ?? '')) === '') {
                continue;
            }
            $pk = function_exists('kop_wbf_person_key') ? kop_wbf_person_key($s['name']) : '';
            $item = $pk !== '' ? ($person_items[$pk] ?? null) : null;
            $cites = array();
            foreach (array_slice($item ? kop_wbf_evidence($item) : array(), 0, 3) as $e) {
                $cites[] = array('label' => $e['label'], 'number' => $e['number'] ?? '', 'page' => (int) $e['page'], 'url' => $e['url']);
            }
            $facts[] = array('key' => $item ? $item['pkey'] : 'f' . $fid . '-staff-' . (++$n), 'group' => 'staff',
                'label' => kop_ya_staff_label($s), 'cites' => $cites, 'by' => $by, 'at' => kop_ya_now());
        }
    }
    foreach ((array) ($id['pastOperators'] ?? array()) as $op) {
        $op = is_array($op) ? ($op['name'] ?? '') : $op;
        if (is_string($op) && trim($op) !== '') {
            $facts[] = array('key' => 'f' . $fid . '-op-' . (++$n), 'group' => 'history', 'label' => 'Operator/owner: ' . trim($op), 'cites' => array());
        }
    }
    foreach ((array) ($loc['formerLocations'] ?? array()) as $fl) {
        $raw = is_array($fl) ? ($fl['raw'] ?? '') : $fl;
        if (is_string($raw) && trim($raw) !== '') {
            $facts[] = array('key' => 'f' . $fid . '-loc-' . (++$n), 'group' => 'history', 'label' => 'Former location: ' . trim($raw), 'cites' => array());
        }
    }
    foreach ((array) ($doc['criticalIncidents']['customIncidents'] ?? array()) as $inc) {
        $text = is_array($inc) ? trim(implode(': ', array_filter(array($inc['date'] ?? '', $inc['description'] ?? ($inc['summary'] ?? ($inc['title'] ?? '')))))) : (string) $inc;
        if ($text === '') {
            continue;
        }
        $key = 'f' . $fid . '-inc-' . (++$n);
        $f = kop_ya_fact_from_note($text, $key);
        $facts[] = $f ? array_merge($f, array('group' => 'incident')) : array('key' => $key, 'group' => 'incident', 'label' => $text, 'cites' => array());
    }
    $notes = array();
    foreach (array_merge((array) ($doc['notes'] ?? array()), (array) ($period['notes'] ?? array())) as $line) {
        $line = is_array($line) ? ($line['text'] ?? '') : $line;
        // Bookkeeping lines (the news scan's, the data migration's) are not for the public page.
        if (!is_string($line) || trim($line) === '' || preg_match('/^(Added \S+ by the news scan|migration:)/i', trim($line))) {
            continue;
        }
        $f = kop_ya_fact_from_note($line, 'f' . $fid . '-note-' . (++$n));
        if ($f) {
            $facts[] = $f;
        } else {
            $notes[] = trim($line);
        }
    }

    $others = array();
    foreach (array_merge((array) ($id['pastNames'] ?? array()), (array) ($id['otherNames'] ?? array())) as $o) {
        $o = is_array($o) ? ($o['name'] ?? '') : $o;
        if (is_string($o) && trim($o) !== '' && kop_ya_key($o) !== kop_ya_key($name)) {
            $others[] = kop_ya_clean_name($o);
        }
    }
    $ar = (array) ($det['ageRange'] ?? array());
    $ages = isset($ar['min']) && $ar['min'] !== null ? ((int) $ar['min'] . (isset($ar['max']) && $ar['max'] !== null ? '-' . (int) $ar['max'] : ' and older')) : '';
    $status = $period['status'] ?? '';
    $fields = array(
        'name'         => $name,
        'other_names'  => implode("\n", array_unique($others)),
        'city'         => $loc['city'] ?? '',
        'state'        => $loc['state'] ?? '',
        'country'      => $loc['country'] ?? '',
        'ages'         => $ages,
        'program_type' => $det['type'] ?? '',
        'run_by'       => $id['currentOperator'] ?? '',
        'opened'       => $period['startYear'] ?? null,
        'closed'       => $period['endYear'] ?? null,
        'status'       => in_array($status, array('Open', 'Closed'), true) ? $status : 'Unknown',
        'notes'        => implode("\n\n", $notes),
        'review'       => 'approved',
        'source'       => 'Moved from facility record ' . $fid,
    );

    // The folder its /facility/ page showed becomes the program's, unless the program has one.
    $index = function_exists('kop_facility_pages_index') ? kop_facility_pages_index() : array();
    $folder = (int) ($index['ids'][$fid]['folder'] ?? 0) ?: (int) ($doc['documentFolderId'] ?? 0);

    kop_ya_install($pdo);
    $t = kop_migration_tables($prefix);
    $yid = kop_v2_with_write_lock($pdo, function () use ($pdo, $t, $fid, $fields, $facts, $name, $by, $prefix, $folder, $into, $article_lines) {
        $have = $into ? kop_ya_get($pdo, $into) : kop_ya_find_by_name($pdo, $name);
        $yid = $have ? (int) $have['id'] : kop_ya_save($pdo, $fields, 0, $by);
        if ($have && ($into || $article_lines)) {
            // A second record of the program: its name and its articles join the program's.
            $others = kop_ya_lines($have['other_names']);
            if (kop_ya_key($name) !== kop_ya_key($have['name']) && !in_array(kop_ya_key($name), array_map('kop_ya_key', $others), true)) {
                $others[] = $name;
            }
            $links = kop_ya_lines($have['links']);
            foreach ($article_lines as $line) {
                if (!in_array($line, $links, true)) $links[] = $line;
            }
            $pdo->prepare('UPDATE young_adult_programs SET other_names = ?, links = ?, updated_at = ? WHERE id = ?')
                ->execute(array(implode("\n", $others), implode("\n", $links), kop_ya_now(), $yid));
        } elseif ($article_lines) {
            $pdo->prepare('UPDATE young_adult_programs SET links = ?, updated_at = ? WHERE id = ?')
                ->execute(array(implode("\n", $article_lines), kop_ya_now(), $yid));
        }
        if ($folder > 0) {
            $pdo->prepare('UPDATE young_adult_programs SET document_folder_id = ? WHERE id = ? AND (document_folder_id IS NULL OR document_folder_id = 0)')->execute(array($folder, $yid));
        }
        $all = kop_ya_facts(kop_ya_get($pdo, $yid));
        $keys = array_column($all, 'key');
        foreach ($facts as $f) {
            if (!in_array($f['key'], $keys, true)) {
                $all[] = $f;
            }
        }
        $pdo->prepare('UPDATE young_adult_programs SET facts = ?, updated_at = ? WHERE id = ?')->execute(array(wp_json_encode($all), kop_ya_now(), $yid));

        foreach (array($t['facility_locations'], $t['operator_facilities'], $t['identity']) as $table) {
            $pdo->prepare("DELETE FROM `{$table}` WHERE facility_id = ?")->execute(array($fid));
        }
        try {
            // Links to deleted articles, and the articles now listed on the program.
            $pdo->prepare('DELETE FROM news_facility_links WHERE facility_id = ?')->execute(array($fid));
        } catch (PDOException $e) {
            // No news tables on this install.
        }
        try {
            $pdo->prepare('DELETE FROM `' . $prefix . 'kop_facility_addresses` WHERE facility = ?')->execute(array($name));
        } catch (PDOException $e) {
            // No address table on this install.
        }
        try {
            $pdo->prepare("UPDATE facility_closure_reports SET facility_id = NULL, status = 'unmatched' WHERE facility_id = ? AND status IN ('pending','already')")
                ->execute(array($fid));
        } catch (PDOException $e) {
            // No closure reports table on this install.
        }
        try {
            $pdo->prepare("UPDATE news_facility_candidates SET decision = 'not_facility', facility_id = NULL, updated_at = ? WHERE facility_id = ?")
                ->execute(array(kop_ya_now(), $fid));
        } catch (PDOException $e) {
            // No news scan tables on this install.
        }
        $pdo->prepare("DELETE FROM `{$t['facilities']}` WHERE id = ?")->execute(array($fid));
        return $yid;
    });

    // Woodbury Facts: added items now point at the program; waiting ones move to its tab.
    if ($wbf_table !== '') {
        $p = kop_ya_get($pdo, $yid);
        $keys = array_column(kop_ya_facts($p), 'key');
        foreach ($wbf as $r) {
            $wpdb->update($wbf_table, array('applied_fid' => 0, 'facility_id' => 0, 'ya' => 1,
                'applied' => wp_json_encode(array('filed' => 'young_adult', 'id' => $yid, 'name' => $p['name'],
                    'done' => array('id' => $yid, 'key' => in_array($r['pkey'], $keys, true) ? $r['pkey'] : '', 'filled' => array())))),
                array('pkey' => $r['pkey']));
        }
        $note = 'Was on facility record ' . $fid . ', moved to the young adult program ' . $name . '.';
        $wpdb->query($wpdb->prepare("UPDATE {$wbf_table} SET facility_id = 0, ya = 1, match_kind = 'none', match_note = %s WHERE status IN ('pending', 'gone') AND facility_id = %d", $note, $fid));
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT program FROM {$wbf_table} WHERE match_note = %s", $note)) as $program) {
            kop_wbf_ya_override($program, 1);
        }
    }
    // A record added to a program it did not make (a second copy, a name the program already had): kop_ya_moved_from_map() reads this too.
    $p = kop_ya_get($pdo, $yid);
    if ($p && !preg_match('/facility record ' . $fid . '\b/', (string) $p['source'])) {
        $into = get_option('kop_ya_moved_into', array());
        $into = is_array($into) ? $into : array();
        $into[$fid] = $yid;
        update_option('kop_ya_moved_into', $into, false);
    }
    do_action('kop_facility_status_changed', $fid);
    return $yid;
}

/**
 * Woodbury Facts items the 2026-09-30 move left "added" with no fact of
 * theirs on the program (kop_ya_move_facility() kept a fact's key only when
 * it was the item's own; the rest went in under the record's keys, or into
 * the program's fields). Each is pointed at the program's fact that says the
 * same (its label, the same or the fact's label at its start), so Undo takes
 * that off; an item nothing on the program says is added now as its own
 * fact (kop_wbf_ya_fact(), filling empty fields as filing does). Returns
 * [pkey => 'linked <fact key>' | 'added' | why not].
 */
function kop_ya_relink_moved_items(PDO $pdo) {
    global $wpdb;
    if (!function_exists('kop_wbf_table') || !function_exists('kop_wbf_ya_fact')) {
        return array();
    }
    $table = kop_wbf_table();
    $rows = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'applied' AND ya = 1 AND applied LIKE '%\"filed\":\"young_adult\"%'", ARRAY_A);
    $out = array();
    foreach ($rows as $r) {
        $applied = json_decode((string) $r['applied'], true);
        if (!is_array($applied) || ($applied['done']['key'] ?? null) !== '') {
            continue;
        }
        $yid = (int) ($applied['done']['id'] ?? ($applied['id'] ?? 0));
        $p = $yid ? kop_ya_get($pdo, $yid) : null;
        if (!$p) {
            $out[$r['pkey']] = 'program ' . $yid . ' is gone';
            continue;
        }
        $label = kop_ya_key($r['label']);
        $match = '';
        foreach (kop_ya_facts($p) as $f) {
            $fl = kop_ya_key($f['label'] ?? '');
            if ($fl !== '' && ($fl === $label || strpos($label, $fl . ' ') === 0)) {
                $match = (string) $f['key'];
                break;
            }
        }
        if ($match !== '') {
            $applied['done'] = array('id' => $yid, 'key' => $match, 'filled' => array());
            $out[$r['pkey']] = 'linked ' . $match;
        } else {
            list($fact, $fill) = kop_wbf_ya_fact($r);
            $fact['by'] = (string) $r['reviewed_by'];
            $fact['at'] = (string) $r['reviewed_at'];
            try {
                $applied['done'] = kop_ya_add_fact($pdo, $yid, $fact, $fill);
            } catch (RuntimeException $e) {
                $out[$r['pkey']] = $e->getMessage();
                continue;
            }
            $out[$r['pkey']] = 'added';
        }
        $wpdb->update($table, array('applied' => wp_json_encode($applied)), array('pkey' => $r['pkey']));
    }
    return $out;
}

/** Run kop_ya_relink_moved_items() once (2026-10-21); what it did is kept in kop_ya_relink_log. */
function kop_ya_maybe_relink() {
    $version = '1';
    if (get_option('kop_ya_relinked') === $version) {
        return;
    }
    $pdo = kop_ya_pdo();
    if (!$pdo || !function_exists('kop_wbf_table') || !kop_ya_ready($pdo)) {
        return;
    }
    update_option('kop_ya_relinked', $version, false);
    try {
        update_option('kop_ya_relink_log', kop_ya_relink_moved_items($pdo), false);
    } catch (Throwable $e) {
        update_option('kop_ya_relink_log', array('error' => $e->getMessage()), false);
    }
}
add_action('admin_init', 'kop_ya_maybe_relink', 21);

/**
 * The facility records the owner asked to move on 2026-09-30: every one
 * whose age range starts at 17 or older (checked on production that day).
 * Each is moved only if it is still there under this name and its ages
 * still start at 17 or older.
 */
function kop_ya_first_moves() {
    return array(
        9708   => 'Homelines',
        9744   => 'Optimum Performance Institute',
        10461  => 'Legacy Outdoor Adventures',
        10741  => 'Fulshear Treatment to Transition – The Ranch',
        12204  => 'Cottonwood Tucson',
        100137 => 'Four Circles Recovery Center',
        100165 => 'Dragonfly Transitions',
    );
}

/** Version 1: create the table and make the moves above; what happened is kept in kop_ya_migration_log. */
function kop_ya_maybe_migrate() {
    $version = '1';
    if (get_option('kop_ya_migrated') === $version) {
        return;
    }
    $pdo = kop_ya_pdo();
    if (!$pdo || !function_exists('kop_wbf_ensure_table')) {
        return;
    }
    update_option('kop_ya_migrated', $version);
    $log = array();
    try {
        kop_ya_install($pdo);
        kop_wbf_ensure_table();
        require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
        global $wpdb;
        foreach (kop_ya_first_moves() as $fid => $want) {
            try {
                $stored = kop_facility_load($fid, array('pdo' => $pdo, 'prefix' => $wpdb->prefix));
                $name = $stored ? kop_ya_clean_name(($stored['doc']['identification']['name'] ?? '') ?: $stored['unique_name']) : '';
                $min = $stored['doc']['facilityDetails']['ageRange']['min'] ?? null;
                if (!$stored || kop_ya_key($name) !== kop_ya_key($want) || $min === null || (int) $min < 17) {
                    $log[] = "Facility record $fid was not there as $want with ages from 17; left alone.";
                    continue;
                }
                $yid = kop_ya_move_facility($pdo, $fid, 'migration');
                $log[] = "Moved facility record $fid ($name) to young adult program $yid.";
            } catch (Throwable $e) {
                $log[] = "Facility record $fid ($want) not moved: " . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $log[] = 'Stopped: ' . $e->getMessage();
        update_option('kop_ya_migrated', '');
    }
    update_option('kop_ya_migration_log', array('time' => time(), 'lines' => $log), false);
}
add_action('admin_init', 'kop_ya_maybe_migrate', 20);

/**
 * The facility-era folders of the programs moved on 2026-09-30, found on
 * production 2026-10-21 (the copy holding the files where a name has empty
 * duplicates) and approved by the owner: [program id => [name, folder id]].
 * Austin Sendero Recovery (11) had none.
 */
function kop_ya_folder_links() {
    return array(
        1 => array('Homelines', 3136),
        2 => array('Optimum Performance Institute', 258),
        3 => array('Legacy Outdoor Adventures', 3888),
        4 => array('Fulshear Treatment to Transition – The Ranch', 4163),
        5 => array('Cottonwood Tucson', 5687),
        6 => array('Four Circles Recovery Center', 193),
        7 => array('Dragonfly Transitions', 8228),
    );
}

/**
 * Facility records that are second copies of moved programs (the owner,
 * 2026-10-21): [facility id => [name, state, program id, its folder to link
 * to the program's, or 0]]. Four Circles and Dragonfly came back from
 * seeds/new-facilities.json the night of the first move. Fulshear's one
 * article becomes a link on the program.
 */
function kop_ya_twin_moves() {
    return array(
        100254 => array('Four Circles Recovery Center', 'NC', 6, 0),
        100255 => array('Dragonfly Transitions', 'OR', 7, 0),
        10612  => array('Fulshear Treatment to Transition', 'TX', 4, 4162),
    );
}

/**
 * Version 1: link the folders above (only to a program with none, to a folder
 * that is still there), then move the twins into their programs, each only if
 * it is still there under that name and state. A twin's own folder is linked
 * to the program's (kop_folder_links, as api/link-folders.php does), so its
 * documents stay on the program's card. What happened is kept in
 * kop_ya_twins_log.
 */
function kop_ya_maybe_link_and_move() {
    $version = '1';
    if (get_option('kop_ya_twins_done') === $version) {
        return;
    }
    $pdo = kop_ya_pdo();
    if (!$pdo || !function_exists('kop_wbf_ensure_table')) {
        return;
    }
    update_option('kop_ya_twins_done', $version);
    global $wpdb;
    $log = array();
    $live = function ($folder) use ($wpdb) {
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'fbv WHERE id = %d', (int) $folder)) > 0;
    };
    try {
        kop_ya_install($pdo);
        foreach (kop_ya_folder_links() as $yid => $link) {
            $p = kop_ya_get($pdo, $yid);
            if (!$p || kop_ya_key($p['name']) !== kop_ya_key($link[0])) {
                $log[] = "Program $yid is not {$link[0]}; folder {$link[1]} not linked.";
            } elseif ((int) $p['document_folder_id'] > 0) {
                $log[] = "Program $yid ({$p['name']}) has folder {$p['document_folder_id']} already.";
            } elseif (!$live($link[1])) {
                $log[] = "Folder {$link[1]} is gone; program $yid gets a new one when something is filed.";
            } else {
                $pdo->prepare('UPDATE young_adult_programs SET document_folder_id = ? WHERE id = ?')->execute(array((int) $link[1], $yid));
                $log[] = "Program $yid ({$p['name']}) has folder {$link[1]}.";
            }
        }
        kop_wbf_ensure_table();
        require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
        foreach (kop_ya_twin_moves() as $fid => $twin) {
            list($want, $state, $yid, $folder) = $twin;
            try {
                $stored = kop_facility_load($fid, array('pdo' => $pdo, 'prefix' => $wpdb->prefix));
                $name = $stored ? kop_ya_clean_name(($stored['doc']['identification']['name'] ?? '') ?: $stored['unique_name']) : '';
                $at = $stored ? strtoupper((string) ($stored['doc']['location']['state'] ?? '')) : '';
                if (!$stored || kop_ya_key($name) !== kop_ya_key($want) || $at !== $state) {
                    $log[] = "Facility record $fid was not there as $want ($state); left alone.";
                    continue;
                }
                $moved = kop_ya_move_facility($pdo, $fid, 'migration', array('into' => $yid, 'articles' => 'links'));
                $log[] = "Moved facility record $fid ($name) into young adult program $moved.";
                $p = kop_ya_get($pdo, $moved);
                $mine = (int) ($p['document_folder_id'] ?? 0);
                if ($folder && $mine && $folder !== $mine && $live($folder) && function_exists('kop_folder_links_install')) {
                    try {
                        kop_folder_links_install();
                        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . $wpdb->prefix . 'kop_folder_links (folder_a, folder_b, note) VALUES (%d, %d, %s)',
                            min($folder, $mine), max($folder, $mine), 'Same program: facility record ' . $fid . ' moved into young adult program ' . $moved));
                        $log[] = "Linked its folder $folder to the program's folder $mine.";
                    } catch (Throwable $e) {
                        $log[] = "Its folder $folder was not linked to the program's folder $mine: " . $e->getMessage();
                    }
                }
            } catch (Throwable $e) {
                $log[] = "Facility record $fid ($want) not moved: " . $e->getMessage();
            }
        }
        if (function_exists('kop_facility_pages_flush_index')) {
            kop_facility_pages_flush_index();
        }
        do_action('litespeed_purge_all');
    } catch (Throwable $e) {
        $log[] = 'Stopped: ' . $e->getMessage();
        update_option('kop_ya_twins_done', '');
    }
    update_option('kop_ya_twins_log', array('time' => time(), 'lines' => $log), false);
}
add_action('admin_init', 'kop_ya_maybe_link_and_move', 22);

/**
 * Facility records found later to be young adult programs (the owner,
 * 2026-10-26): [facility id => [name, state, ages from its own site]]. The
 * record has no age range, so the ages go on the program after the move.
 * A linked article becomes a link on the program (it stays published).
 */
function kop_ya_later_moves() {
    return array(
        // threestrandswilderness.com (Wayback 2020-11-27): "young adult men, ages 18-27".
        100175 => array('Three Strands Wilderness', 'CO', '18-27'),
    );
}

/** Version 1: move the records above, each only if it is still there under that name and state; log in kop_ya_later_log. */
function kop_ya_maybe_later_moves() {
    $version = '1';
    if (get_option('kop_ya_later_done') === $version) {
        return;
    }
    $pdo = kop_ya_pdo();
    if (!$pdo || !function_exists('kop_wbf_ensure_table')) {
        return;
    }
    update_option('kop_ya_later_done', $version);
    global $wpdb;
    $log = array();
    try {
        kop_ya_install($pdo);
        kop_wbf_ensure_table();
        require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
        foreach (kop_ya_later_moves() as $fid => $move) {
            list($want, $state, $ages) = $move;
            try {
                $stored = kop_facility_load($fid, array('pdo' => $pdo, 'prefix' => $wpdb->prefix));
                $name = $stored ? kop_ya_clean_name(($stored['doc']['identification']['name'] ?? '') ?: $stored['unique_name']) : '';
                $at = $stored ? strtoupper((string) ($stored['doc']['location']['state'] ?? '')) : '';
                if (!$stored || kop_ya_key($name) !== kop_ya_key($want) || $at !== $state) {
                    $log[] = "Facility record $fid was not there as $want ($state); left alone.";
                    continue;
                }
                $yid = kop_ya_move_facility($pdo, $fid, 'migration', array('articles' => 'links'));
                $pdo->prepare("UPDATE young_adult_programs SET ages = ? WHERE id = ? AND ages = ''")->execute(array($ages, $yid));
                $log[] = "Moved facility record $fid ($name) to young adult program $yid.";
            } catch (Throwable $e) {
                $log[] = "Facility record $fid ($want) not moved: " . $e->getMessage();
            }
        }
        if (function_exists('kop_facility_pages_flush_index')) {
            kop_facility_pages_flush_index();
        }
        do_action('litespeed_purge_all');
    } catch (Throwable $e) {
        $log[] = 'Stopped: ' . $e->getMessage();
        update_option('kop_ya_later_done', '');
    }
    update_option('kop_ya_later_log', array('time' => time(), 'lines' => $log), false);
}
add_action('admin_init', 'kop_ya_maybe_later_moves', 23);

/**
 * Programs added offline (seeds/young-adult-programs.json), run with the
 * template assignments. Each is added once: a program already on file under
 * that name or one of its other names is left as it is. Returns the new ids.
 */
function kop_ya_apply_seeds() {
    $done = array();
    $path = get_stylesheet_directory() . '/seeds/young-adult-programs.json';
    $pdo = kop_ya_pdo();
    $entries = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!$pdo || !is_array($entries)) {
        return $done;
    }
    try {
        kop_ya_install($pdo);
        foreach ($entries as $f) {
            if (!is_array($f) || kop_ya_find_by_name($pdo, $f['name'] ?? '')) {
                continue;
            }
            $done[] = kop_ya_save($pdo, $f + array('review' => 'approved'), 0, 'seed');
        }
    } catch (Throwable $e) {
        error_log('kop_ya_apply_seeds: ' . $e->getMessage());
    }
    return $done;
}

/* ---- The page -------------------------------------------------------- */

function kop_ya_fact_groups() {
    return array(
        'staff'    => 'People',
        'incident' => 'Incidents',
        'history'  => 'History',
        'details'  => 'About the program',
    );
}

/** "Bend, OR · Ages 18-26 · 2004 to 2012" and so on. */
function kop_ya_meta_line(array $p) {
    $bits = array();
    $place = trim(implode(', ', array_filter(array($p['city'], $p['state']))));
    $country = trim((string) $p['country']);
    if ($country !== '' && !preg_match('/^(us|usa|united states)$/i', $country)) {
        $place = trim(implode(', ', array_filter(array($place, $country))));
    }
    if ($place !== '') {
        $bits[] = $place;
    }
    if (trim((string) $p['ages']) !== '') {
        $bits[] = 'Ages ' . preg_replace('/^ages?\s*/i', '', trim($p['ages']));
    }
    if ($p['opened'] && $p['closed']) {
        $bits[] = (int) $p['opened'] . ' to ' . (int) $p['closed'];
    } elseif ($p['opened']) {
        $bits[] = 'Opened ' . (int) $p['opened'] . ($p['status'] === 'Open' ? ', still open' : '');
    } elseif ($p['closed']) {
        $bits[] = 'Closed ' . (int) $p['closed'];
    } elseif ($p['status'] === 'Closed') {
        $bits[] = 'Closed';
    }
    return implode(' · ', $bits);
}

/** Where a program is listed on the page: its US state, else its country. */
function kop_ya_region_label(array $p) {
    $state = trim((string) $p['state']);
    $country = trim((string) $p['country']);
    if ($country !== '' && !preg_match('/^(us|usa|united states)$/i', $country)) {
        return $country;
    }
    if ($state === '') {
        return 'Place not known';
    }
    $names = function_exists('kop_wb_state_names') ? kop_wb_state_names() : array();
    return $names[$state] ?? $state;
}

/** A fact's words as the page shows them: no review-screen wording. */
function kop_ya_fact_text($label) {
    $label = preg_replace('/ \(sets [^()]*\)$/', '', (string) $label);
    return preg_replace('/\s*\((?:Woodbury Reports|HEAL|r\/troubledteens wiki)[^()]*(?:\([^()]*\)[^()]*)?\)\s*$/', '', $label);
}

/** The approved programs, by state or country, each with its facts and their sources. */
/**
 * The program's documents, as a facility page lists its own
 * (kop_facility_pages_documents()): its folder and every folder of the same
 * name, subfolders kept, through the folder shortcode's function. '' when it
 * has no folder or the folder holds nothing.
 */
function kop_ya_documents_html(array $p) {
    $folder = (int) ($p['document_folder_id'] ?? 0);
    if ($folder <= 0 || !function_exists('kop_filebird_folder_shortcode')) {
        return '';
    }
    $html = (string) kop_filebird_folder_shortcode(array('folder_id' => $folder, 'merge' => 'name', 'layout' => 'grid', 'title' => 'Documents on file', 'show_count' => 'yes'));
    return stripos($html, 'no-documents') !== false ? '' : $html;
}

function kop_ya_render_public() {
    $pdo = kop_ya_pdo();
    if (!$pdo || !kop_ya_ready($pdo)) {
        return;
    }
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $programs = kop_ya_all($pdo, 'approved');
    if (!$programs) {
        echo '<p class="kop-ya-empty">No programs are listed yet.</p>';
        return;
    }
    $by = array();
    foreach ($programs as $p) {
        $by[kop_ya_region_label($p)][] = $p;
    }
    uksort($by, function ($a, $b) {
        $x = $a === 'Place not known' ? 1 : 0;
        $y = $b === 'Place not known' ? 1 : 0;
        return $x === $y ? strcmp($a, $b) : $x - $y;
    });
    $groups = kop_ya_fact_groups();

    echo '<p class="kop-ya-count">' . count($programs) . ' program' . (count($programs) === 1 ? '' : 's') . ' in ' . count($by)
        . ' place' . (count($by) === 1 ? '' : 's') . '.</p>';
    echo '<nav class="kop-ya-jump" aria-label="Places"><ul>';
    foreach (array_keys($by) as $region) {
        echo '<li><a href="#kop-ya-' . $e(sanitize_title($region)) . '">' . $e($region) . '</a></li>';
    }
    echo '</ul></nav>';

    echo '<div class="kop-ya-records">' . "\n";
    foreach ($by as $region => $list) {
        echo '<h2 class="kop-ya-region" id="kop-ya-' . $e(sanitize_title($region)) . '">' . $e($region) . "</h2>\n";
        foreach ($list as $p) {
            echo '<article class="kop-ya-program" id="kop-ya-program-' . (int) $p['id'] . '"'
                . (function_exists('kop_ie_attr') ? kop_ie_attr('ya:' . (int) $p['id'], $p['name']) : '') . '>';
            echo '<h3 class="kop-ya-name">' . $e($p['name']) . '</h3>';
            $meta = kop_ya_meta_line($p);
            if ($meta !== '') {
                echo '<p class="kop-ya-meta">' . $e($meta) . '</p>';
            }
            $others = kop_ya_lines($p['other_names']);
            if ($others) {
                echo '<p class="kop-ya-fact"><strong>Also known as:</strong> ' . $e(implode('; ', $others)) . '</p>';
            }
            if (trim((string) $p['program_type']) !== '') {
                echo '<p class="kop-ya-fact"><strong>Described as:</strong> ' . $e($p['program_type']) . '</p>';
            }
            if (trim((string) $p['run_by']) !== '') {
                echo '<p class="kop-ya-fact"><strong>Run by:</strong> ' . $e($p['run_by']) . '</p>';
            }
            if (trim((string) $p['notes']) !== '' && function_exists('kop_page_text_body_html')) {
                echo kop_page_text_body_html($p['notes'], 'plain', 'kop-ya');
            }
            $facts = array();
            foreach (kop_ya_facts($p) as $f) {
                $facts[$f['group'] ?? 'details'][] = $f;
            }
            foreach ($groups as $g => $title) {
                if (empty($facts[$g])) {
                    continue;
                }
                echo '<p class="kop-ya-list-title">' . $e($title) . '</p><ul class="kop-ya-list">';
                foreach ($facts[$g] as $f) {
                    echo '<li>' . $e(kop_ya_fact_text($f['label'] ?? ''));
                    $cites = array();
                    foreach (array_slice((array) ($f['cites'] ?? array()), 0, 3) as $c) {
                        $text = !empty($c['cite']) ? (string) $c['cite'] : 'Woodbury Reports, ' . $c['label'] . ', p. ' . (int) $c['page'];
                        $cites[] = preg_match('#^https?://#i', (string) ($c['url'] ?? '')) ? '<a href="' . $e($c['url']) . '">' . $e($text) . '</a>' : $e($text);
                    }
                    if ($cites) {
                        echo ' <span class="kop-ya-src">(' . implode('; ', $cites) . ')</span>';
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }
            $links = kop_ya_parse_links($p['links']);
            $docs = kop_ya_documents_html($p);
            if ($links || $docs !== '') {
                echo '<p class="kop-ya-list-title">More about this program</p>';
            }
            if ($links) {
                echo '<ul class="kop-ya-list">';
                foreach ($links as $l) {
                    echo '<li><a href="' . $e($l[1]) . '">' . $e($l[0]) . '</a></li>';
                }
                echo '</ul>';
            }
            if ($docs !== '') {
                echo '<div class="kop-ya-docs"><p class="kop-ya-docs-title">Documents on file</p>' . $docs . '</div>';
            }
            echo "</article>\n";
        }
    }
    echo "</div>\n";
}

add_action('admin_init', function () {
    $pdo = kop_ya_pdo();
    if ($pdo) {
        try {
            kop_ya_install($pdo);
        } catch (Throwable $e) {
            error_log('young_adult_programs install: ' . $e->getMessage());
        }
    }
});

/**
 * [old facilities_v2 id => ['id' => program id, 'name' => ...]] for the
 * programs moved out of the facility records (source "Moved from facility
 * record N", and the kop_ya_moved_into option for a record moved into a
 * program it did not make, kop_ya_move_facility()). Woodbury Facts and Fornits items that
 * still name the old record are filed on the program instead.
 */
function kop_ya_moved_from_map(PDO $pdo = null) {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = array();
    $pdo = $pdo ?: kop_ya_pdo();
    if (!$pdo || !kop_ya_ready($pdo)) return $memo;
    foreach ($pdo->query("SELECT id, name, source FROM young_adult_programs WHERE source LIKE 'Moved from facility record %'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (preg_match('/facility record (\d+)/', (string) $p['source'], $m)) $memo[(int) $m[1]] = array('id' => (int) $p['id'], 'name' => (string) $p['name']);
    }
    // Records moved into a program they did not make (kop_ya_move_facility()).
    foreach ((array) get_option('kop_ya_moved_into', array()) as $fid => $yid) {
        $p = isset($memo[(int) $fid]) ? null : kop_ya_get($pdo, (int) $yid);
        if ($p) $memo[(int) $fid] = array('id' => (int) $p['id'], 'name' => (string) $p['name']);
    }
    return $memo;
}

/**
 * The card a moved facility record's old /facility/ address goes to, or ''.
 * $id is the record id the address carried (a bare id or a "-<id>" slug end),
 * else the old slug is matched against the program's name and state, its
 * other names and the twin records' names (kop_ya_twin_moves()), the way
 * kop_facility_page_slug_candidate() built it.
 */
function kop_ya_moved_facility_target($slug, $id = 0, PDO $pdo = null) {
    $pdo = $pdo ?: kop_ya_pdo();
    if (!$pdo) return '';
    $moved = kop_ya_moved_from_map($pdo);
    if ($id > 0) return isset($moved[$id]) ? kop_ya_url($moved[$id]['id']) : '';
    $slug = strtolower((string) $slug);
    if ($slug === '' || !$moved || !function_exists('kop_facility_page_slug_candidate')) return '';
    $twins = kop_ya_twin_moves();
    $seen = array();
    foreach ($moved as $fid => $m) {
        if (isset($seen[$m['id']])) continue;
        $seen[$m['id']] = true;
        $p = kop_ya_get($pdo, $m['id']);
        if (!$p) continue;
        $names = array_merge(array((string) $p['name']), kop_ya_lines($p['other_names']));
        foreach ($twins as $t) if ((int) $t[2] === (int) $p['id']) $names[] = $t[0];
        foreach ($names as $n) {
            if (kop_facility_page_slug_candidate($n, $p['state'], $p['country']) === $slug) return kop_ya_url($p['id']);
        }
    }
    return '';
}
