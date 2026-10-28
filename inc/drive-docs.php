<?php
/**
 * Drive Docs: links from the owner's Google Docs and Sheets, added after review
 * (docs/PLAN.md 3.9).
 *
 * scripts/gdocs-extract.py reads the HTML/CSV export of the facility docs,
 * keeps every link with the words around it, ties it to a facility (or a
 * company) and drops what is already on file. Its links.json is copied to
 * ~/kop-import/gdocs/ (outside the web root: it quotes private docs).
 *
 * KOP Tools > Drive Docs shows one card per facility with its links. Each
 * link goes where its kind belongs, and the reviewer can send it elsewhere:
 *   - news articles: the news queue, as the browser extension sends them,
 *     naming the facility, waiting for review there (the hourly reader in
 *     api/lib-record-enrich.php fills its summary, date and headline first;
 *     until 2026-10-20 they went in approved, with neither);
 *   - court records and bills: the lawsuits and legislation queues, published
 *     at once (the reviewer has just looked at it);
 *   - the program's own website: the record's profileLinks;
 *   - everything else (licensing reports, survivor posts, staff profiles,
 *     reference, government pages, archive copies): the record's
 *     resourceLinks, shown on the facility page under "Materials and links".
 * Queue inserts send no admin emails. Undo takes back exactly what was added:
 * the link comes off the record, or the queue row it made is removed (news is
 * soft-deleted) unless someone has rejected or filed it since.
 *
 * Decisions live in {prefix}kop_gdoc_links, keyed by the link's address, so a
 * rebuild adds new links and never undoes a decision.
 *
 * Five files feed it (kop_gdl_sources()): links.json (Google Docs),
 * heal-links.json (scripts/heal-docs.py), wiki-links.json (scripts/wiki-links.py),
 * sciad-links.json (scripts/sciad-links.py: SCIAD NET, about 13,000 links)
 * and audit-links.json (scripts/data-audit-links.py: the outside sources the
 * data audit read). A source with a credit puts it, not a doc name, on every
 * record and queue row it fills; the facility page shows it linked. At that
 * volume the screen filters by source, caps each card at KOP_GDL_CARD_ROWS
 * rows (the rest a click away), and a facility card can add all its sure
 * matches at once in batches.
 */

// Dropdown lists (kop_choices(); KOP Tools > Dropdown Lists)
require_once __DIR__ . '/choice-lists.php';


if (!defined('ABSPATH')) {
    exit;
}

// The KOP Tools look (inc/admin-kit.php): its CSS and JS load on this screen.
require_once __DIR__ . '/admin-kit.php';
add_filter('kop_admin_kit_pages', function ($pages) { $pages[] = 'kop-drive-docs'; return $pages; });

define('KOP_GDOC_LINKS_DB_VERSION', '2');
if (!defined('KOP_GDL_CARD_ROWS')) {
    define('KOP_GDL_CARD_ROWS', 40);
}

function kop_gdl_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_gdoc_links';
}

function kop_gdl_ensure_table() {
    if (get_option('kop_gdoc_links_db') === KOP_GDOC_LINKS_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = kop_gdl_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pkey VARCHAR(32) NOT NULL,
        url TEXT NOT NULL,
        original TEXT NULL,
        domain VARCHAR(190) NOT NULL DEFAULT '',
        kind VARCHAR(16) NOT NULL DEFAULT '',
        label TEXT NULL,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        facility_how VARCHAR(40) NOT NULL DEFAULT '',
        also_named TEXT NULL,
        operator_name VARCHAR(255) NOT NULL DEFAULT '',
        source_doc VARCHAR(255) NOT NULL DEFAULT '',
        source VARCHAR(12) NOT NULL DEFAULT '',
        rhash VARCHAR(32) NOT NULL DEFAULT '',
        seen LONGTEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        applied LONGTEXT NULL,
        applied_fid BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reviewed_by VARCHAR(60) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY pkey (pkey),
        KEY status_fac (status, facility_id),
        KEY operator_name (operator_name(80)),
        KEY source_doc (source_doc(80)),
        KEY source_status (source, status, facility_id)
    ) {$charset};");
    // Rows from before version 2 name their source only in source_doc.
    $wpdb->query("UPDATE {$table} SET source = CASE WHEN source_doc LIKE 'HEAL archive%' THEN 'heal' "
        . "WHEN source_doc LIKE 'r/troubledteens wiki%' THEN 'wiki' ELSE 'gdocs' END WHERE source = ''");
    update_option('kop_gdoc_links_db', KOP_GDOC_LINKS_DB_VERSION);
}

function kop_gdl_path() {
    $dir = defined('KOP_GDOCS_IMPORT_DIR') ? rtrim(KOP_GDOCS_IMPORT_DIR, '/') : dirname(rtrim(ABSPATH, '/')) . '/kop-import/gdocs';
    return $dir . '/links.json';
}

/**
 * The files the screen reads, all in ~/kop-import/gdocs/: the Google Docs
 * pass, the documents from HEAL's archived site (scripts/heal-docs.py), the
 * links on the r/troubledteens wiki's pages (scripts/wiki-links.py) and SCIAD
 * NET's links (scripts/sciad-links.py). A source with a credit is named,
 * linked, on whatever it fills (owner decision 18 for SCIAD NET); the others
 * name the doc or page they came from.
 */
function kop_gdl_sources() {
    return array(
        'gdocs' => array('label' => 'Google Docs', 'file' => 'links.json'),
        'heal'  => array('label' => 'HEAL archive', 'file' => 'heal-links.json'),
        'wiki'  => array('label' => 'r/troubledteens wiki', 'file' => 'wiki-links.json'),
        'sciad' => array(
            'label'      => 'SCIAD NET',
            'file'       => 'sciad-links.json',
            'credit'     => 'SCIAD NET',
            'credit_url' => 'https://web.archive.org/web/20221007171605/https://www.sciad.net/',
            'submitter'  => 'SCIAD NET import',
        ),
        // The data audit's sources (docs/PLAN.md 3.13): KOP's own research, so no outside credit.
        'audit' => array('label' => 'Data audit', 'file' => 'audit-links.json', 'submitter' => 'Data audit import'),
    );
}

/** source => readable path, for the files that are there. */
function kop_gdl_files() {
    $dir = dirname(kop_gdl_path());
    $out = array();
    foreach (kop_gdl_sources() as $key => $s) {
        $p = $dir . '/' . $s['file'];
        if (is_readable($p)) {
            $out[$key] = $p;
        }
    }
    return $out;
}

/** Every links file the screen reads (kop_gdl_sources()). */
function kop_gdl_paths() {
    return array_values(kop_gdl_files());
}

/**
 * The build's kinds, as the review screen names them: kind => label. The kinds,
 * with where Add sends each and its "Materials and links" heading, are in
 * inc/choice-lists/sorting.php (KOP Tools > Dropdown Lists); $mode 'offered'
 * leaves out hidden ones (dropdowns).
 */
function kop_gdl_kinds($mode = 'all') {
    $out = array();
    foreach (kop_choices('drive-docs-kinds', null, $mode) as $k => $c) $out[$k] = kop_choice_label($c);
    return $out;
}

/** Where a link can go. */
function kop_gdl_targets() {
    return array(
        'resource'    => 'Resource link on the facility page',
        'news'        => 'News queue',
        'website'     => "Program's website (profile links)",
        'lawsuit'     => 'Lawsuits queue',
        'legislation' => 'Legislation queue',
    );
}

function kop_gdl_default_target($kind) {
    $c = kop_choices('drive-docs-kinds')[$kind] ?? null;
    $t = is_array($c) ? (string) ($c['target'] ?? '') : '';
    return isset(kop_gdl_targets()[$t]) ? $t : 'resource';
}

/** A build kind as a resourceLinks kind (kop_facility_resource_link_kinds()). */
function kop_gdl_resource_kind($kind) {
    $c = kop_choices('drive-docs-kinds')[$kind] ?? null;
    $rk = is_array($c) ? (string) ($c['resource_kind'] ?? '') : '';
    return $rk !== '' && isset(kop_facility_resource_link_kinds()[$rk]) ? $rk : 'other';
}

/** Facility links that need a record (the queues do not). */
function kop_gdl_needs_facility($target) {
    return in_array($target, array('resource', 'website'), true);
}

function kop_gdl_pkey($key) {
    return substr(md5((string) $key), 0, 16);
}

/**
 * Load a new links.json. New links are added, waiting ones take the build's
 * latest match, decided ones are left alone, and waiting ones the build no
 * longer offers (now on file, or gone from the docs) are marked 'gone'.
 */
function kop_gdl_sync($force = false) {
    $files = kop_gdl_files();
    if (!$files) {
        return null;
    }
    $md5 = md5(implode('|', array_map('md5_file', $files)));
    if (!$force && get_option('kop_gdoc_links_md5') === $md5) {
        return null;
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(300); // The first SCIAD NET load is about 13,000 rows.
    }
    $items = array();
    foreach ($files as $source => $path) {
        $list = json_decode((string) file_get_contents($path), true);
        if (!is_array($list)) {
            return null; // A half-copied file: nothing is marked gone until it reads.
        }
        foreach ($list as $it) {
            if (is_array($it)) {
                $it['_source'] = $source;
                $items[] = $it;
            }
        }
        unset($list);
    }
    global $wpdb;
    $table = kop_gdl_table();
    $existing = $hashes = array();
    foreach ((array) $wpdb->get_results("SELECT pkey, status, rhash FROM {$table}", ARRAY_A) as $r) {
        $existing[$r['pkey']] = $r['status'];
        $hashes[$r['pkey']] = (string) $r['rhash'];
    }
    $kinds = kop_gdl_kinds();
    $sources = kop_gdl_sources();
    $now = current_time('mysql', true);
    $seen = array();
    $added = $updated = 0;
    $batch = array();
    foreach ($items as $it) {
        if (!empty($it['on_file']) || ($it['category'] ?? '') === 'internal') {
            continue;
        }
        $url = (string) ($it['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }
        $pkey = kop_gdl_pkey($it['key'] ?? $url);
        if (isset($seen[$pkey])) {
            continue; // The same address in two files: the first file's row stands.
        }
        $seen[$pkey] = true;
        $places = array_values(array_filter((array) ($it['seen'] ?? array()), 'is_array'));
        $source = (string) ($it['source'] ?? '');
        $row = array(
            'url'           => $url,
            'original'      => (string) ($it['original'] ?? ''),
            'domain'        => mb_substr((string) ($it['domain'] ?? ''), 0, 190),
            'kind'          => isset($kinds[$it['category'] ?? '']) ? $it['category'] : 'other',
            'label'         => (string) ($it['label'] ?? ''),
            'facility_id'   => (int) ($it['facility']['id'] ?? 0),
            'facility_how'  => mb_substr((string) ($it['facility_how'] ?? ''), 0, 40),
            'also_named'    => wp_json_encode(array_values((array) ($it['also_named'] ?? array()))),
            'operator_name' => mb_substr((string) ($it['operator']['name'] ?? ''), 0, 255),
            'source_doc'    => mb_substr((string) ($places[0]['doc'] ?? ''), 0, 255),
            'seen'          => wp_json_encode(array_slice($places, 0, 12)),
            'source'        => isset($sources[$source]) ? $source : $it['_source'],
        );
        $row['rhash'] = md5(wp_json_encode($row));
        if (!isset($existing[$pkey])) {
            $row['pkey'] = $pkey;
            $row['status'] = 'pending';
            $row['created_at'] = $now;
            $batch[] = $row;
            if (count($batch) >= 200) {
                $added += kop_gdl_insert_rows($batch);
                $batch = array();
            }
        } elseif ($existing[$pkey] === 'gone' || ($existing[$pkey] === 'pending' && $hashes[$pkey] !== $row['rhash'])) {
            // A waiting row takes the build's latest match; an unchanged one is left alone.
            $row['status'] = 'pending';
            $wpdb->update($table, $row, array('pkey' => $pkey));
            $updated++;
        }
    }
    if ($batch) {
        $added += kop_gdl_insert_rows($batch);
    }
    $gone = 0;
    foreach ($existing as $pkey => $status) {
        if ($status === 'pending' && !isset($seen[$pkey])) {
            $gone += (int) $wpdb->update($table, array('status' => 'gone'), array('pkey' => $pkey));
        }
    }
    update_option('kop_gdoc_links_md5', $md5, false);
    return array('added' => $added, 'updated' => $updated, 'gone' => $gone);
}

/** Insert new rows a batch at a time (one row at a time when a batch is refused). Returns how many went in. */
function kop_gdl_insert_rows(array $rows) {
    global $wpdb;
    if (!$rows) {
        return 0;
    }
    $table = kop_gdl_table();
    $cols = array_keys($rows[0]);
    $one = '(' . implode(',', array_fill(0, count($cols), '%s')) . ')';
    $values = array();
    foreach ($rows as $r) {
        foreach ($cols as $c) {
            $values[] = (string) $r[$c];
        }
    }
    $sql = $wpdb->prepare("INSERT INTO {$table} (`" . implode('`,`', $cols) . '`) VALUES ' . implode(',', array_fill(0, count($rows), $one)), $values);
    if ($wpdb->query($sql) !== false) {
        return count($rows);
    }
    $n = 0;
    foreach ($rows as $r) {
        if ($wpdb->insert($table, $r) !== false) {
            $n++;
        }
    }
    return $n;
}

function kop_gdl_rows(array $pkeys) {
    global $wpdb;
    $pkeys = array_values(array_filter(array_map(function ($k) { return preg_replace('/[^a-f0-9]/', '', (string) $k); }, $pkeys)));
    if (!$pkeys) {
        return array();
    }
    $in = implode(',', array_fill(0, count($pkeys), '%s'));
    return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_gdl_table() . " WHERE pkey IN ({$in})", $pkeys), ARRAY_A);
}

/* ---- Facility records ---------------------------------------------------- */

function kop_gdl_opts() {
    global $wpdb;
    $pdo = function_exists('kop_closure_pdo') ? kop_closure_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet.');
    }
    return array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
}

/** Save a changed document through the legacy shape, as Woodbury Facts does, so it comes out current. */
function kop_gdl_save(array $doc, array $opts) {
    $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    kop_facility_save($out, $opts);
}

/** Address key that ignores the scheme, www. and a trailing slash (kop_facility_resource_link_list()). */
function kop_gdl_url_key($url) {
    return strtolower(rtrim(preg_replace('#^https?://(www\.)?#i', '', trim((string) $url)), '/'));
}

/** The words that say where a link came from, for the record and the queues. */
function kop_gdl_source_line(array $r) {
    $src = kop_gdl_sources()[$r['source'] ?? ''] ?? array();
    if (!empty($src['credit'])) {
        // SCIAD NET: the credit, never the collection path (which names programs' folders, not KOP's words).
        return $src['credit'];
    }
    $doc = $r['source_doc'] !== '' ? $r['source_doc'] : 'a Google Doc';
    // HEAL's documents, the wiki's links and the data audit's sources name their own source ("HEAL archive:
    // heal-online.org/x.pdf, saved 2009", "r/troubledteens wiki: page "X" (as of 2025-12-18)", "KOP data audit").
    foreach (array('HEAL archive', 'r/troubledteens wiki', 'KOP data audit') as $own) {
        if (strpos($doc, $own) === 0) {
            return $doc;
        }
    }
    return 'Google Doc: ' . $doc;
}

/**
 * Add one link to a document. Returns what was done, for Undo; throws when
 * the record already has it.
 */
function kop_gdl_doc_add(array &$doc, array $r, $target) {
    $key = kop_gdl_url_key($r['url']);
    foreach (array_merge((array) ($doc['profileLinks'] ?? array()), array_column((array) ($doc['resourceLinks'] ?? array()), 'url')) as $have) {
        if (is_string($have) && kop_gdl_url_key($have) === $key) {
            throw new RuntimeException('Already on the record.');
        }
    }
    if ($target === 'website') {
        $doc['profileLinks'][] = $r['url'];
        return array('target' => 'website', 'url' => $r['url']);
    }
    $doc['resourceLinks'][] = array(
        'url'    => $r['url'],
        'label'  => (string) $r['label'],
        'kind'   => kop_gdl_resource_kind($r['kind']),
        'source' => kop_gdl_source_line($r),
    );
    return array('target' => 'resource', 'url' => $r['url']);
}

function kop_gdl_doc_remove(array &$doc, array $done) {
    $key = kop_gdl_url_key($done['url'] ?? '');
    if ($key === '') {
        return;
    }
    if (($done['target'] ?? '') === 'website') {
        $doc['profileLinks'] = array_values(array_filter((array) ($doc['profileLinks'] ?? array()), function ($u) use ($key) {
            return !is_string($u) || kop_gdl_url_key($u) !== $key;
        }));
    } else {
        $doc['resourceLinks'] = array_values(array_filter((array) ($doc['resourceLinks'] ?? array()), function ($l) use ($key) {
            return !is_array($l) || kop_gdl_url_key($l['url'] ?? '') !== $key;
        }));
    }
}

/* ---- The queues ---------------------------------------------------------- */

function kop_gdl_queue_pdo() {
    if (!function_exists('kop_ext_load_record_libs')) {
        throw new RuntimeException('The records helpers are not loaded.');
    }
    kop_ext_load_record_libs();
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('The records database is not reachable.');
    }
    return $pdo;
}

/** The submission note a queue row carries: where it came from, with the credit's link and the archived copy. */
function kop_gdl_queue_note(array $r, array $first) {
    $src = kop_gdl_sources()[$r['source'] ?? ''] ?? array();
    $note = kop_gdl_source_line($r);
    if (!empty($src['credit_url'])) {
        $note = 'Found in ' . $note . ' (' . $src['credit_url'] . ')';
    }
    if (!empty($first['archive']) && preg_match('#^https?://#i', (string) $first['archive'])) {
        $note .= "\n\nArchived copy: " . $first['archive'];
    }
    if (!empty($first['text'])) {
        $note .= "\n\n" . (!empty($src['credit']) ? 'Entry: ' : 'Words around the link: ')
            . '"' . mb_substr((string) $first['text'], 0, 600) . '"';
    }
    return $note;
}

/** Send one link to a queue. Returns what was done; throws when it is already there. */
function kop_gdl_queue_add(PDO $pdo, array $r, $target, $facility_name, $reviewer) {
    $type = array('news' => 'article', 'lawsuit' => 'lawsuit', 'legislation' => 'legislation')[$target];
    $seen = json_decode((string) $r['seen'], true) ?: array();
    $first = $seen[0] ?? array();
    $src = kop_gdl_sources()[$r['source'] ?? ''] ?? array();
    $p = array(
        'url'       => $r['url'],
        'title'     => $r['label'] !== '' ? $r['label'] : $r['url'],
        'type'      => $type,
        // SCIAD NET rows name the outlet and the day; the others only have the address.
        'site_name' => !empty($first['outlet']) ? (string) $first['outlet'] : $r['domain'],
        'facility'  => (string) $facility_name,
    );
    if (!empty($first['published'])) {
        $p['published'] = (string) $first['published'];
    }
    $archive = !empty($first['archive']) && preg_match('#^https?://#i', (string) $first['archive']) ? (string) $first['archive'] : '';
    $dupes = kop_ext_find_duplicates($pdo, $p);
    if (!$dupes && $archive !== '') {
        $dupes = kop_ext_find_duplicates($pdo, array('url' => $archive, 'type' => $type));
    }
    if ($dupes) {
        throw new RuntimeException('Already in the ' . $dupes[0]['type'] . ' records (#' . (int) $dupes[0]['id'] . ').');
    }
    $note = kop_gdl_queue_note($r, $first);
    $submitter = mb_substr($reviewer . ' (' . ($src['submitter'] ?? 'Google Docs import') . ')', 0, 255);
    $quiet = function () { return false; };
    add_filter('kop_notify_admins_enabled', $quiet);
    try {
        if ($target === 'news') {
            $id = kop_ext_insert_news($pdo, $p, $submitter, $note);
        } elseif ($target === 'lawsuit') {
            $id = kop_ext_insert_lawsuit($pdo, $p, $submitter, $note);
        } else {
            $id = kop_ext_insert_legislation($pdo, $p, $submitter, $note);
        }
    } finally {
        remove_filter('kop_notify_admins_enabled', $quiet);
    }
    // News waits in Submissions Review > News (owner, 2026-10-20): a bare link has no summary and often the
    // wrong date until the hourly reader (api/lib-record-enrich.php) fills it, so it is approved there.
    if ($target === 'news') {
        return array('target' => $target, 'id' => (int) $id, 'live' => false);
    }
    kop_gdl_queue_go_live($pdo, $target, (int) $id, $reviewer);
    return array('target' => $target, 'id' => (int) $id, 'live' => true);
}

/**
 * Adding a court record or bill from Drive Docs is the review: the row it made
 * goes live as approving it in its own queue would (published), with the same
 * case links api/manage-submissions.php makes. News never comes here.
 */
function kop_gdl_queue_go_live(PDO $pdo, $target, $id, $reviewer) {
    if ($target === 'news') {
        $pdo->prepare("UPDATE news_submissions SET status = 'approved', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute(array(mb_substr((string) $reviewer, 0, 255), (int) $id));
    } else {
        $table = $target === 'lawsuit' ? 'lawsuits' : 'legislation';
        $pdo->prepare("UPDATE {$table} SET publication_status = 'published', published_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute(array((int) $id));
    }
    // Facility and case links, story groups, and a case's documents filed,
    // as approving it in its own queue does (api/lib-record-resync.php).
    require_once get_stylesheet_directory() . '/api/lib-record-resync.php';
    $type = $target === 'news' ? 'news' : ($target === 'lawsuit' ? 'lawsuit' : 'legislation');
    kop_resync_after_status($pdo, $type, array((int) $id), $target === 'news' ? 'approved' : 'published', (string) $reviewer);
}

/**
 * Take a queue row back: one still waiting, or one Drive Docs put live
 * ($done['live']) that nobody has rejected or filed elsewhere since.
 */
function kop_gdl_queue_remove(PDO $pdo, array $done) {
    $id = (int) ($done['id'] ?? 0);
    $live = !empty($done['live']);
    if ($done['target'] === 'news') {
        $st = $pdo->prepare("UPDATE news_submissions SET status = 'deleted' WHERE id = ? AND status IN ('submitted'" . ($live ? ", 'approved'" : '') . ')');
        $st->execute(array($id));
        if (!$st->rowCount()) {
            throw new RuntimeException('News item #' . $id . ' has been reviewed in the news queue already; remove it there.');
        }
        require_once get_stylesheet_directory() . '/api/lib-record-resync.php';
        kop_resync_news($pdo, array($id), 'drive docs undo');
        return;
    }
    $table = $done['target'] === 'lawsuit' ? 'lawsuits' : 'legislation';
    $st = $pdo->prepare("DELETE FROM {$table} WHERE id = ? AND publication_status IN ('pending'" . ($live ? ", 'published'" : '') . ')');
    $st->execute(array($id));
    if (!$st->rowCount()) {
        throw new RuntimeException(ucfirst($done['target']) . ' #' . $id . ' has been reviewed already; remove it there.');
    }
    require_once get_stylesheet_directory() . '/api/lib-record-resync.php';
    kop_records_deleted($pdo, $table === 'lawsuits' ? 'lawsuit' : 'bill', array($id));
}

/* ---- Add, skip, undo ----------------------------------------------------- */

/**
 * Add links. $targets maps pkey => target (else the kind's default); $fid
 * is the record the facility links go on (0: only queue links can go).
 * Returns [pkey => result].
 */
function kop_gdl_apply(array $rows, array $targets, $fid, $reviewer) {
    global $wpdb;
    $fid = (int) $fid;
    $valid = kop_gdl_targets();
    $results = array();
    $now = current_time('mysql', true);
    $mark = function ($r, $status, $applied, $applied_fid) use ($wpdb, $reviewer, $now) {
        $wpdb->update(kop_gdl_table(), array('status' => $status, 'applied' => wp_json_encode($applied),
            'applied_fid' => (int) $applied_fid, 'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
    };

    $facility_name = '';
    if ($fid > 0) {
        $pdo = kop_closure_pdo();
        $name = $pdo ? $pdo->prepare('SELECT name FROM facilities_v2 WHERE id = ?') : null;
        if ($name) {
            $name->execute(array($fid));
            $facility_name = (string) $name->fetchColumn();
        }
        if ($facility_name === '') {
            throw new RuntimeException("Facility #{$fid} does not exist.");
        }
    }

    $on_record = array();
    $queue_pdo = null;
    foreach ($rows as $r) {
        if ($r['status'] !== 'pending') {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Already ' . $r['status'] . '.');
            continue;
        }
        $t = (string) ($targets[$r['pkey']] ?? '');
        $t = isset($valid[$t]) ? $t : kop_gdl_default_target($r['kind']);
        if (kop_gdl_needs_facility($t)) {
            if ($fid <= 0) {
                $results[$r['pkey']] = array('ok' => false, 'error' => 'Pick the facility first.', 'keep' => true);
            } else {
                $on_record[] = array($r, $t);
            }
            continue;
        }
        try {
            $queue_pdo = $queue_pdo ?: kop_gdl_queue_pdo();
            $done = kop_gdl_queue_add($queue_pdo, $r, $t, $facility_name, $reviewer);
            $mark($r, 'applied', $done, $fid);
            $results[$r['pkey']] = array('ok' => true, 'target' => $t);
        } catch (RuntimeException $e) {
            $mark($r, 'rejected', array('reason' => $e->getMessage()), 0);
            $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
        }
    }

    if ($on_record) {
        $opts = kop_gdl_opts();
        kop_v2_with_write_lock($opts['pdo'], function () use ($on_record, $fid, $opts, $mark, &$results) {
            $stored = kop_facility_load($fid, $opts);
            if (!$stored) {
                throw new RuntimeException("Facility #{$fid} does not exist.");
            }
            $doc = $stored['doc'];
            $applied = array();
            foreach ($on_record as list($r, $t)) {
                try {
                    $applied[$r['pkey']] = array($r, kop_gdl_doc_add($doc, $r, $t));
                    $results[$r['pkey']] = array('ok' => true, 'target' => $t);
                } catch (RuntimeException $e) {
                    $mark($r, 'rejected', array('reason' => $e->getMessage()), 0);
                    $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
                }
            }
            if ($applied) {
                kop_gdl_save($doc, $opts);
                foreach ($applied as list($r, $done)) {
                    $mark($r, 'applied', $done, $fid);
                }
            }
        });
    }
    return $results;
}

/* ---- Company pages ---------------------------------------------------- */

/** A name as a key for company names: case, punctuation, "&", a leading "The", "Inc" do not count. */
function kop_gdl_company_key($s) {
    if (function_exists('kop_autoconn_key')) return kop_autoconn_key($s);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $s)));
}

/** The company record whose name (or another name of it) is exactly $name, or 0 when none or several. */
function kop_gdl_company_id($name) {
    static $index = null;
    if ($index === null) {
        global $wpdb;
        $index = array();
        foreach ((array) $wpdb->get_results('SELECT id, name, json_data FROM ' . $wpdb->prefix . 'kop_operators', ARRAY_A) as $o) {
            $j = json_decode((string) $o['json_data'], true);
            $op = is_array($j['operator'] ?? null) ? $j['operator'] : array();
            $names = array_merge(array($o['name'], $op['name'] ?? '', $op['currentName'] ?? ''), (array) ($op['otherNames'] ?? array()));
            foreach ($names as $n) {
                $k = kop_gdl_company_key(is_array($n) ? ($n['name'] ?? '') : $n);
                if ($k !== '') $index[$k][(int) $o['id']] = true;
            }
        }
    }
    $ids = $index[kop_gdl_company_key($name)] ?? array();
    return count($ids) === 1 ? (int) key($ids) : 0;
}

function kop_gdl_company_load($oid) {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare('SELECT json_data FROM ' . $wpdb->prefix . 'kop_operators WHERE id = %d', (int) $oid));
    $json = $raw !== null ? json_decode((string) $raw, true) : null;
    if (!is_array($json) || !is_array($json['operator'] ?? null)) throw new RuntimeException('Company #' . (int) $oid . ' does not exist.');
    return $json;
}

/** Save a company's JSON; its page and the company index start over. */
function kop_gdl_company_save($oid, array $json) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'kop_operators SET json_data = %s, updated_at = %s WHERE id = %d',
        wp_json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), current_time('mysql', true), (int) $oid));
    if (function_exists('kop_operator_pages_flush_index')) kop_operator_pages_flush_index();
    if (function_exists('kop_ie_purge_url') && function_exists('kop_operator_page_url')) kop_ie_purge_url((string) kop_operator_page_url((int) $oid));
}

/**
 * Add links to a company's page: the company's own site to its websites, the
 * rest to its "Materials and links" (operator.resourceLinks, as on a facility
 * page). News, court records and bills go to their queues as Add sends them.
 * $targets (pkey => target) overrides a link's default place, as for
 * kop_gdl_apply(). Returns [pkey => result].
 */
function kop_gdl_apply_company(array $rows, $oid, $reviewer, array $targets = array()) {
    global $wpdb;
    $oid = (int) $oid;
    $json = kop_gdl_company_load($oid);
    $results = array();
    $queue = array();
    $now = current_time('mysql', true);
    $changed = array();
    foreach ($rows as $r) {
        if ($r['status'] !== 'pending') {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Already ' . $r['status'] . '.');
            continue;
        }
        $t = (string) ($targets[$r['pkey']] ?? '');
        $t = isset(kop_gdl_targets()[$t]) ? $t : kop_gdl_default_target($r['kind']);
        if (!kop_gdl_needs_facility($t)) {
            $queue[] = $r;
            continue;
        }
        $key = kop_gdl_url_key($r['url']);
        $have = array_merge(array_map(function ($w) { return is_array($w) ? ($w['url'] ?? '') : $w; }, (array) ($json['operator']['websites'] ?? array())),
            array_column((array) ($json['operator']['resourceLinks'] ?? array()), 'url'));
        $dupe = false;
        foreach ($have as $h) $dupe = $dupe || (is_string($h) && kop_gdl_url_key($h) === $key);
        if ($dupe) {
            $wpdb->update(kop_gdl_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => 'Already on the company page.')),
                'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Already on the company page.');
            continue;
        }
        if ($t === 'website') {
            $json['operator']['websites'][] = $r['url'];
        } else {
            $json['operator']['resourceLinks'][] = array('url' => $r['url'], 'label' => (string) $r['label'],
                'kind' => kop_gdl_resource_kind($r['kind']), 'source' => kop_gdl_source_line($r));
        }
        $changed[$r['pkey']] = array('target' => 'company', 'place' => $t, 'operator_id' => $oid, 'url' => $r['url']);
    }
    if ($changed) {
        kop_gdl_company_save($oid, $json);
        foreach ($changed as $pkey => $done) {
            $wpdb->update(kop_gdl_table(), array('status' => 'applied', 'applied' => wp_json_encode($done), 'applied_fid' => 0,
                'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $pkey));
            $results[$pkey] = array('ok' => true, 'target' => 'company');
        }
    }
    if ($queue) $results += kop_gdl_apply($queue, $targets, 0, $reviewer);
    return $results;
}

/**
 * Add links to the record a review inbox picker names ("12" a program,
 * "<kind>:<id>" anything else; kop_rinbox_record_of()). A program goes through
 * kop_gdl_apply() and a company through kop_gdl_apply_company(), as before.
 * Any other record (consultant, transport company, provider, marketing company,
 * trade organization, young adult program) gets each facility-page link through
 * kop_rinbox_file_on_record() (a young adult program's links, else a line in
 * its notes), stored as {target: 'record', place, url, record: <what it did>}
 * for kop_gdl_undo(). News, court records and bills still go to their queues.
 * Returns [pkey => result].
 */
function kop_gdl_apply_record(array $rows, array $targets, $value, $reviewer) {
    global $wpdb;
    if (!function_exists('kop_rinbox_record_of')) throw new RuntimeException('Pick a program for this link.');
    list($kind, $id) = kop_rinbox_record_of($value);
    if ($kind === '' || $id <= 0) throw new RuntimeException('Pick a record in the finder first.');
    if ($kind === 'facility') return kop_gdl_apply($rows, $targets, $id, $reviewer);
    if ($kind === 'company') return kop_gdl_apply_company($rows, $id, $reviewer, $targets);
    $valid = kop_gdl_targets();
    $results = array();
    $queue = array();
    $now = current_time('mysql', true);
    foreach ($rows as $r) {
        if ($r['status'] !== 'pending') {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Already ' . $r['status'] . '.');
            continue;
        }
        $t = (string) ($targets[$r['pkey']] ?? '');
        $t = isset($valid[$t]) ? $t : kop_gdl_default_target($r['kind']);
        if (!kop_gdl_needs_facility($t)) {
            $queue[] = $r;
            continue;
        }
        try {
            $done = kop_rinbox_file_on_record($value, array('what' => $t === 'website' ? 'website' : 'link', 'url' => (string) $r['url'],
                'title' => (string) $r['label'], 'kind' => kop_gdl_resource_kind($r['kind']), 'source' => kop_gdl_source_line($r),
                'key' => 'gdl-' . $r['pkey']), $reviewer);
        } catch (RuntimeException $e) {
            if (stripos($e->getMessage(), 'already') === false) {
                $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage(), 'keep' => true);
                continue;
            }
            $wpdb->update(kop_gdl_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => $e->getMessage())),
                'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
            $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
            continue;
        }
        $wpdb->update(kop_gdl_table(), array('status' => 'applied', 'applied_fid' => 0, 'reviewed_by' => $reviewer, 'reviewed_at' => $now,
            'applied' => wp_json_encode(array('target' => 'record', 'place' => $t, 'url' => (string) $r['url'], 'record' => $done))), array('pkey' => $r['pkey']));
        $results[$r['pkey']] = array('ok' => true, 'target' => 'record', 'message' => (string) ($done['message'] ?? ''));
    }
    if ($queue) $results += kop_gdl_apply($queue, $targets, 0, $reviewer);
    return $results;
}

/** Take links Add put on a company page off it again. $list: [[row, done], ...] for one company. */
function kop_gdl_company_remove($oid, array $list) {
    $json = kop_gdl_company_load($oid);
    $keys = array();
    foreach ($list as $pair) $keys[kop_gdl_url_key($pair[1]['url'] ?? '')] = true;
    $json['operator']['websites'] = array_values(array_filter((array) ($json['operator']['websites'] ?? array()), function ($w) use ($keys) {
        return !isset($keys[kop_gdl_url_key(is_array($w) ? ($w['url'] ?? '') : (string) $w)]);
    }));
    $json['operator']['resourceLinks'] = array_values(array_filter((array) ($json['operator']['resourceLinks'] ?? array()), function ($l) use ($keys) {
        return !is_array($l) || !isset($keys[kop_gdl_url_key($l['url'] ?? '')]);
    }));
    kop_gdl_company_save($oid, $json);
}

function kop_gdl_undo(array $rows, $reviewer) {
    global $wpdb;
    $results = array();
    $back = function ($r) use ($wpdb, $reviewer) {
        $wpdb->update(kop_gdl_table(), array('status' => 'pending', 'applied' => null, 'applied_fid' => 0,
            'reviewed_by' => $reviewer, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
    };
    $by_fid = array();
    $by_company = array();
    $queue_pdo = null;
    foreach ($rows as $r) {
        $done = json_decode((string) $r['applied'], true) ?: array();
        if ($r['status'] === 'rejected' || $r['status'] === 'gone') {
            $back($r);
            $results[$r['pkey']] = array('ok' => true);
        } elseif ($r['status'] !== 'applied') {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Nothing to undo.');
        } elseif (in_array($done['target'] ?? '', array('resource', 'website'), true)) {
            $by_fid[(int) $r['applied_fid']][] = array($r, $done);
        } elseif (($done['target'] ?? '') === 'company') {
            $by_company[(int) ($done['operator_id'] ?? 0)][] = array($r, $done);
        } elseif (($done['target'] ?? '') === 'record') {
            // On a consultant, transporter, provider, marketer, trade organization or young adult program (kop_gdl_apply_record()).
            try {
                if (!function_exists('kop_rinbox_unfile_from_record')) throw new RuntimeException('Undo this from the Review inbox.');
                kop_rinbox_unfile_from_record((array) ($done['record'] ?? array()));
                $back($r);
                $results[$r['pkey']] = array('ok' => true);
            } catch (Throwable $e) {
                $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
            }
        } else {
            try {
                $queue_pdo = $queue_pdo ?: kop_gdl_queue_pdo();
                kop_gdl_queue_remove($queue_pdo, $done);
                $back($r);
                $results[$r['pkey']] = array('ok' => true);
            } catch (RuntimeException $e) {
                $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
            }
        }
    }
    foreach ($by_company as $oid => $list) {
        try {
            kop_gdl_company_remove($oid, $list);
            foreach ($list as $pair) {
                $back($pair[0]);
                $results[$pair[0]['pkey']] = array('ok' => true);
            }
        } catch (RuntimeException $e) {
            foreach ($list as $pair) $results[$pair[0]['pkey']] = array('ok' => false, 'error' => $e->getMessage());
        }
    }
    if ($by_fid) {
        $opts = kop_gdl_opts();
        foreach ($by_fid as $fid => $list) {
            kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $list, $opts, $back, &$results) {
                $stored = kop_facility_load($fid, $opts);
                if (!$stored) {
                    throw new RuntimeException("Facility #{$fid} does not exist.");
                }
                $doc = $stored['doc'];
                foreach ($list as list($r, $done)) {
                    kop_gdl_doc_remove($doc, $done);
                }
                kop_gdl_save($doc, $opts);
                foreach ($list as list($r, $done)) {
                    $back($r);
                    $results[$r['pkey']] = array('ok' => true);
                }
            });
        }
    }
    return $results;
}

add_action('wp_ajax_kop_gdl_act', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }
    check_ajax_referer('kop_drive_docs', 'nonce');
    $act = sanitize_key($_POST['act'] ?? '');
    $keys = isset($_POST['keys']) && is_array($_POST['keys']) ? array_slice($_POST['keys'], 0, 400) : array();
    $rows = kop_gdl_rows($keys);
    $user = wp_get_current_user()->user_login;
    try {
        if ($act === 'apply_card') {
            // "Add all" on a facility card: its sure matches under the screen's filters, a batch per request.
            $fid = (int) ($_POST['fid'] ?? 0);
            if ($fid <= 0) {
                throw new RuntimeException('No facility on this card.');
            }
            global $wpdb;
            $where = kop_gdl_card_all_where($fid, kop_gdl_filters($_POST));
            $batch = (array) $wpdb->get_results('SELECT * FROM ' . kop_gdl_table() . " WHERE {$where} ORDER BY id LIMIT 40", ARRAY_A);
            $results = $batch ? kop_gdl_apply($batch, array(), $fid, $user) : array();
            $left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_gdl_table() . " WHERE {$where}");
            wp_send_json_success(array('results' => $results, 'left' => $left));
        }
        if (!$rows) {
            throw new RuntimeException('Nothing selected.');
        }
        if ($act === 'apply') {
            $targets = array();
            foreach ((array) ($_POST['targets'] ?? array()) as $k => $v) {
                $targets[preg_replace('/[^a-f0-9]/', '', (string) $k)] = sanitize_key($v);
            }
            $fid = (int) ($_POST['fid'] ?? 0);
            $results = kop_gdl_apply($rows, $targets, $fid, $user);
            $url = $fid && function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '';
            $label = $fid && function_exists('kop_facility_finder_label') ? wp_strip_all_tags(kop_facility_finder_label(kop_closure_pdo(), $fid)) : '';
            wp_send_json_success(array('results' => $results, 'url' => $url, 'label' => $label));
        }
        if ($act === 'reject') {
            $now = current_time('mysql', true);
            foreach ($rows as $r) {
                if ($r['status'] === 'pending') {
                    $GLOBALS['wpdb']->update(kop_gdl_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => 'Skipped')),
                        'reviewed_by' => $user, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
                }
            }
            wp_send_json_success(array('results' => array_fill_keys(array_column($rows, 'pkey'), array('ok' => true))));
        }
        if ($act === 'undo') {
            wp_send_json_success(array('results' => kop_gdl_undo($rows, $user)));
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});

/* ---- Review screen ------------------------------------------------------ */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Drive Docs', 'Drive Docs', 'manage_options',
        'kop-drive-docs', 'kop_render_drive_docs_page');
}, 21);

function kop_gdl_tabs() {
    return array(
        'facility' => array('label' => 'For a facility', 'where' => "status = 'pending' AND facility_id > 0", 'card' => 'facility_id'),
        'company'  => array('label' => 'Company only', 'where' => "status = 'pending' AND facility_id = 0 AND operator_name <> ''", 'card' => 'operator_name'),
        'none'     => array('label' => 'No facility', 'where' => "status = 'pending' AND facility_id = 0 AND operator_name = ''", 'card' => 'source_doc'),
        'applied'  => array('label' => 'Added', 'where' => "status = 'applied'", 'card' => "CASE WHEN applied_fid > 0 THEN CAST(applied_fid AS CHAR) ELSE 'q' END"),
        'rejected' => array('label' => 'Skipped or already there', 'where' => "status = 'rejected'", 'card' => 'source_doc'),
    );
}

/** A match the screen trusts enough to tick the link to start with. */
function kop_gdl_sure_match(array $r) {
    return $r['facility_id'] > 0 && strpos($r['facility_how'], 'close') === false;
}

/** The screen's filters from a request ($_GET, or the POST of an "add all"): kind, words, source, one card. */
function kop_gdl_filters(array $in) {
    $kinds = kop_gdl_kinds();
    $sources = kop_gdl_sources();
    $text = function ($v) {
        $v = is_string($v) ? $v : '';
        return trim(sanitize_text_field(function_exists('wp_unslash') ? wp_unslash($v) : $v));
    };
    $kind = (string) ($in['gdl_kind'] ?? '');
    $src = (string) ($in['gdl_src'] ?? '');
    return array(
        'kind' => isset($kinds[$kind]) ? $kind : '',
        'src'  => isset($sources[$src]) ? $src : '',
        'q'    => $text($in['gdl_q'] ?? ''),
        'card' => $text($in['gdl_card'] ?? ''),
    );
}

/** The SQL condition for a tab under the kind, source and words filters (not the card). */
function kop_gdl_where($tab, array $f) {
    global $wpdb;
    $tabs = kop_gdl_tabs();
    $where = $tabs[$tab]['where'];
    if (($f['kind'] ?? '') !== '') {
        $where .= $wpdb->prepare(' AND kind = %s', $f['kind']);
    }
    if (($f['src'] ?? '') !== '') {
        $where .= $wpdb->prepare(' AND source = %s', $f['src']);
    }
    if (($f['q'] ?? '') !== '') {
        $like = '%' . $wpdb->esc_like($f['q']) . '%';
        $where .= $wpdb->prepare(' AND (label LIKE %s OR url LIKE %s OR source_doc LIKE %s OR operator_name LIKE %s)', $like, $like, $like, $like);
    }
    return $where;
}

/** Rows within a card in the order the screen reads them: news first. */
function kop_gdl_kind_order_sql() {
    $order = array('news', 'court', 'legislation', 'inspection', 'government', 'social', 'people', 'advertising',
        'reference', 'archive', 'program_site', 'other');
    $sql = 'CASE kind';
    foreach ($order as $i => $k) {
        $sql .= " WHEN '{$k}' THEN {$i}";
    }
    return $sql . ' ELSE 99 END';
}

/**
 * One page of cards, biggest first: array(total cards, array of array(card value, rows)).
 * With $card set, just that card.
 */
function kop_gdl_card_page($tab, $where, $paged, $per, $card = '') {
    global $wpdb;
    $table = kop_gdl_table();
    $col = kop_gdl_tabs()[$tab]['card'];
    if ($card !== '') {
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}" . $wpdb->prepare(" AND {$col} = %s", $card));
        return array($n ? 1 : 0, $n ? array(array($card, $n)) : array());
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT {$col}) FROM {$table} WHERE {$where}");
    $list = (array) $wpdb->get_results("SELECT {$col} AS ck, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY {$col} "
        . 'ORDER BY COUNT(*) DESC, MIN(label) LIMIT ' . (max(1, (int) $paged) - 1) * (int) $per . ', ' . (int) $per, ARRAY_A);
    return array($total, array_map(function ($r) { return array((string) $r['ck'], (int) $r['n']); }, $list));
}

/** A card's rows, $limit at a time. */
function kop_gdl_card_rows($tab, $where, $card, $limit, $offset = 0) {
    global $wpdb;
    $col = kop_gdl_tabs()[$tab]['card'];
    return (array) $wpdb->get_results('SELECT * FROM ' . kop_gdl_table() . " WHERE {$where}" . $wpdb->prepare(" AND {$col} = %s", $card)
        . ' ORDER BY ' . kop_gdl_kind_order_sql() . ', label, id LIMIT ' . (int) $offset . ', ' . (int) $limit, ARRAY_A);
}

/** What "Add all" takes for a facility card: its sure matches under the filters. */
function kop_gdl_card_all_where($fid, array $f) {
    global $wpdb;
    return kop_gdl_where('facility', $f) . $wpdb->prepare(' AND facility_id = %d AND facility_how NOT LIKE %s', (int) $fid, '%close%');
}

function kop_render_drive_docs_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    global $wpdb;
    kop_gdl_ensure_table();
    $sync = kop_gdl_sync(isset($_GET['gdl_resync']));
    // Links of a merged-away record go to the record it was merged into (links.json carries the old id).
    if ($sync && function_exists('kop_fmerge_follow_waiting')) kop_fmerge_follow_waiting();
    $table = kop_gdl_table();
    $tabs = kop_gdl_tabs();
    $kinds = kop_gdl_kinds();
    $sources = kop_gdl_sources();
    $tab = isset($_GET['gdl_tab'], $tabs[$_GET['gdl_tab']]) ? $_GET['gdl_tab'] : 'facility';
    $f = kop_gdl_filters($_GET);
    $paged = max(1, (int) ($_GET['gdl_page'] ?? 1));
    $rpage = max(1, (int) ($_GET['gdl_rpage'] ?? 1));
    $per = 15;
    $card_rows = $f['card'] !== '' ? 200 : KOP_GDL_CARD_ROWS;
    $base = admin_url('admin.php?page=kop-drive-docs');
    $link = function (array $args) use ($base, $tab, $f) {
        $q = array_merge(array('gdl_tab' => $tab, 'gdl_kind' => $f['kind'], 'gdl_src' => $f['src'], 'gdl_q' => $f['q']), $args);
        return add_query_arg(array_map(function ($v) { return $v === '' || $v === null ? null : $v; }, $q), $base);
    };

    $notices = '';
    if ($sync) {
        $notices .= '<div class="notice notice-info"><p>Loaded a new build: ' . (int) $sync['added'] . ' new, ' . (int) $sync['updated']
            . ' updated, ' . (int) $sync['gone'] . ' no longer offered.</p></div>';
    }
    if (!kop_gdl_paths()) {
        $notices .= '<div class="notice notice-warning"><p>No links uploaded yet. Run <code>python scripts/gdocs-extract.py</code> and copy '
            . '<code>tmp/gdocs/links.json</code> to <code>' . esc_html(dirname(kop_gdl_path())) . '</code>.</p></div>';
    }
    echo '<div class="wrap kop-ak kop-gdl">';
    echo kop_ak_header('Drive Docs', array(
        'lede' => 'Links the database does not have yet, one card per facility: from your Google Docs and Sheets, HEAL\'s old site '
            . '(through the Wayback Machine), the r/troubledteens wiki, the outside sources the data audit read, and '
            . '<a href="' . esc_url($sources['sciad']['credit_url']) . '" target="_blank" rel="noopener">SCIAD NET</a>. '
            . 'Pick a source to work through one at a time.',
        'notices' => $notices,
        'steps' => array(
            '<strong>Read down a card.</strong> Links whose facility is a sure match start ticked; untick anything that is not about this place.',
            '<strong>Check <em>Goes to</em>.</strong> News goes to the news queue, court records and bills to their queues, the program\'s own site to its website links, '
                . 'and everything else (licensing reports, survivor posts, staff profiles, reference) to the resource links on the facility page. Change it on any row.',
            '<strong>Click <em>Add checked</em></strong>, or <em>Add everything ticked on this page</em> at the top. Facility links show on the page at once; '
                . 'news waits in Submissions Review &gt; News for its summary and date and your approval there; court records and bills go into their queues already approved (on the site at once, no second approval). No emails. A card with many links has '
                . '<em>Add all sure matches</em>, which adds every sure match the card holds under the filters you picked, in batches.',
            '<strong>Wrong place?</strong> Open <em>Wrong record?</em> under the card, pick another record and click <em>Add checked to that record</em>. '
                . 'On <em>Company only</em> and <em>No facility</em>, pick the record first; news can go to the queue without one.',
            '<strong>Changed your mind?</strong> <em>Undo</em> shows beside the result of every Add and Skip, and the <em>Added</em> tab has Undo too: '
                . 'the link comes off the record, or leaves its queue if nobody has reviewed it yet.',
        ),
    ));

    $where = kop_gdl_where($tab, $f);
    $src_where = $f['src'] !== '' ? $wpdb->prepare(' AND source = %s', $f['src']) : '';

    $tab_items = array();
    foreach ($tabs as $k => $t) {
        $tab_items[$k] = array('label' => $t['label'], 'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE " . $t['where'] . $src_where));
    }
    echo kop_ak_tabs($tab_items, $tab, function ($k) use ($base, $f) {
        return add_query_arg(array('gdl_tab' => $k, 'gdl_src' => $f['src'] !== '' ? $f['src'] : null), $base);
    });

    $by_src = array();
    foreach ((array) $wpdb->get_results("SELECT source, COUNT(*) AS n FROM {$table} WHERE " . $tabs[$tab]['where'] . ' GROUP BY source', ARRAY_A) as $r) {
        $by_src[(string) $r['source']] = (int) $r['n'];
    }
    $src_options = array();
    foreach ($sources as $k => $s) {
        $src_options[$k] = $s['label'] . ' (' . number_format($by_src[$k] ?? 0) . ')';
    }
    echo kop_ak_filters(array('page' => 'kop-drive-docs', 'gdl_tab' => $tab), array(
        array('type' => 'search', 'name' => 'gdl_q', 'value' => $f['q'], 'placeholder' => 'Words, address or doc', 'label' => 'Search'),
        array('type' => 'select', 'name' => 'gdl_src', 'value' => $f['src'], 'label' => 'Source', 'all' => 'Every source', 'options' => $src_options),
        array('type' => 'select', 'name' => 'gdl_kind', 'value' => $f['kind'], 'label' => 'Kind', 'all' => 'Every kind', 'options' => $kinds),
    ), $f['card'] !== '' || $f['q'] !== '' || $f['kind'] !== '' || $f['src'] !== '' ? add_query_arg('gdl_tab', $tab, $base) : '');
    if ($f['card'] !== '') {
        echo '<p><a href="' . esc_url($link(array())) . '">&larr; Every card</a></p>';
    }

    list($total, $cards) = kop_gdl_card_page($tab, $where, $paged, $per, $f['card']);
    if (!$cards) {
        echo kop_ak_empty('Nothing here' . ($f['q'] !== '' || $f['kind'] !== '' || $f['src'] !== '' ? ' under these filters.' : '.')) . '</div>';
        kop_gdl_render_assets();
        return;
    }

    if ($tab === 'facility') {
        echo kop_ak_bar(kop_ak_button('Add everything ticked on this page', 'primary', 'data-kop-ak-all="apply"'));
    }

    $offset = $f['card'] !== '' ? ($rpage - 1) * $card_rows : 0;
    foreach ($cards as list($c, $n)) {
        $rows = kop_gdl_card_rows($tab, $where, $c, $card_rows, $offset);
        if (!$rows) {
            continue;
        }
        $meta = array('total' => $n, 'shown' => count($rows), 'offset' => $offset, 'filters' => $f, 'all' => 0,
            'more' => $n > count($rows) + $offset && $f['card'] === '' ? $link(array('gdl_card' => $c)) : '');
        if ($tab === 'facility' && $n > 1) {
            $meta['all'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE " . kop_gdl_card_all_where((int) $c, $f));
        }
        kop_gdl_render_card($rows, $tab, $meta);
        if ($f['card'] !== '' && $n > $card_rows) {
            echo kop_ak_pager($rpage, (int) ceil($n / $card_rows), $n, 'links on this card',
                function ($p) use ($link, $c) { return $link(array('gdl_card' => $c, 'gdl_rpage' => $p)); });
        }
    }

    if ($f['card'] === '') {
        echo kop_ak_pager($paged, (int) ceil($total / $per), $total, 'cards', function ($p) use ($link) { return $link(array('gdl_page' => $p)); });
    }
    kop_gdl_render_assets();
    echo '</div>';
}

/**
 * One card. $meta (from the screen): total rows the card holds under the
 * filters, how many are shown, the "show them all" link, how many sure
 * matches "Add all" would take, and the filters it takes them under.
 */
function kop_gdl_render_card(array $rows, $tab, array $meta = array()) {
    $first = $rows[0];
    $pending = in_array($tab, array('facility', 'company', 'none'), true);
    $fid = $tab === 'applied' ? (int) $first['applied_fid'] : (int) $first['facility_id'];
    $f = $meta['filters'] ?? array();
    echo '<section class="kop-ak-card kop-gdl-card" data-fid="' . $fid . '" data-kind="' . esc_attr($f['kind'] ?? '') . '" data-src="'
        . esc_attr($f['src'] ?? '') . '" data-q="' . esc_attr($f['q'] ?? '') . '">';
    $sub = array();
    if ($tab === 'facility' || ($tab === 'applied' && $fid)) {
        $title = function_exists('kop_facility_finder_label') && kop_closure_pdo()
            ? kop_facility_finder_label(kop_closure_pdo(), $fid) : '#' . $fid;
        $page = function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '';
        if ($page) {
            $sub[] = '<a href="' . esc_url($page) . '" target="_blank" rel="noopener">Facility page</a>';
        }
    } elseif ($tab === 'company') {
        $title = esc_html($first['operator_name']);
        $sub[] = 'A company: pick the facility for these links.';
    } elseif ($tab === 'applied') {
        $title = 'Sent to the queues';
    } else {
        $title = esc_html($first['source_doc'] !== '' ? $first['source_doc'] : 'Other links');
        $sub[] = 'From this doc; no facility found.';
    }
    $total = (int) ($meta['total'] ?? count($rows));
    if ($total > count($rows)) {
        $sub[] = 'Showing ' . ((int) ($meta['offset'] ?? 0) + 1) . '-' . ((int) ($meta['offset'] ?? 0) + count($rows))
            . ' of ' . $total . ' links.' . (!empty($meta['more']) ? ' <a href="' . esc_url($meta['more']) . '">Show them all</a>, 200 a page.' : '');
    }
    echo kop_ak_card_head($title, implode('<br>', $sub));

    echo '<table class="widefat kop-gdl-table"><tbody>';
    foreach ($rows as $r) {
        kop_gdl_render_row($r, $tab);
    }
    echo '</tbody></table>';

    $buttons = '';
    if ($pending) {
        $buttons .= kop_ak_button($tab === 'facility' ? 'Add checked' : 'Add checked (news only, until a record is picked)', 'primary', 'data-act="apply"')
            . kop_ak_button('Skip checked', 'danger', 'data-act="reject"');
        if ($tab === 'facility' && !empty($meta['all'])) {
            $all = (int) $meta['all'];
            $buttons .= kop_ak_button('Add all ' . $all . ' sure match' . ($all === 1 ? '' : 'es'), 'secondary',
                'data-left="' . $all . '" title="Each to where its kind goes, under the filters above, including links not shown"', 'kop-gdl-addall');
        }
        $buttons .= kop_ak_fold($tab === 'facility' ? 'Wrong record? Put the checked links on another one' : 'Which record is it?',
            kop_facility_finder_field('', '', ' class="kop-ak-fid"') . ' '
            . kop_ak_button('Add checked to that record', 'primary', 'data-act="apply" data-other="1"'), $tab !== 'facility');
    } else {
        $buttons .= kop_ak_button($tab === 'applied' ? 'Undo checked' : 'Put checked back to review', 'secondary', 'data-act="undo"');
    }
    echo kop_ak_card_actions($buttons);
    echo '</section>';
}

function kop_gdl_render_row(array $r, $tab) {
    $pending = $r['status'] === 'pending';
    $kinds = kop_gdl_kinds();
    $checked = $pending && $tab === 'facility' && kop_gdl_sure_match($r);
    $seen = json_decode((string) $r['seen'], true) ?: array();
    $first = $seen[0] ?? array();
    echo '<tr data-key="' . esc_attr($r['pkey']) . '">' . kop_ak_pick($checked);
    echo '<td class="kop-gdl-what"><a href="' . esc_url($r['url']) . '" target="_blank" rel="noopener noreferrer nofollow"><strong>'
        . esc_html($r['label'] !== '' ? $r['label'] : $r['url']) . '</strong></a>'
        . '<div class="kop-ak-muted">' . esc_html($kinds[$r['kind']] ?? $r['kind']) . ' &middot; ' . esc_html($r['domain']) . '</div>';
    if ($pending) {
        $default = kop_gdl_default_target($r['kind']);
        echo '<label class="kop-ak-goes">Goes to <select class="kop-ak-target">';
        foreach (kop_gdl_targets() as $k => $label) {
            echo '<option value="' . esc_attr($k) . '"' . selected($default, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        if ($r['facility_id'] > 0 && !kop_gdl_sure_match($r)) {
            echo '<div class="kop-ak-warn">Matched by a close name (' . esc_html($r['facility_how']) . '): check it is this facility. Starts unticked.</div>';
        }
    } else {
        $done = json_decode((string) $r['applied'], true) ?: array();
        if (!empty($done['reason'])) {
            echo '<div class="kop-ak-muted">' . esc_html($done['reason']) . '</div>';
        } elseif (($done['target'] ?? '') === 'record' && !empty($done['record']['name'])) {
            echo '<div class="kop-ak-muted">Went to: ' . esc_html($done['record']['name']) . '</div>';
        } elseif (!empty($done['target'])) {
            $targets = kop_gdl_targets();
            echo '<div class="kop-ak-muted">Went to: ' . esc_html($targets[$done['target']] ?? $done['target'])
                . (!empty($done['id']) ? ' #' . (int) $done['id'] : '') . '</div>';
        }
    }
    echo '</td><td class="kop-gdl-ev">';
    $where = trim(($first['doc'] ?? '') . (!empty($first['tab']) ? ' > ' . $first['tab'] : '') . (!empty($first['heading']) ? ' > ' . $first['heading'] : ''));
    echo '<div class="kop-ak-muted">From ' . esc_html($where) . (count($seen) > 1 ? ' (and ' . (count($seen) - 1) . ' more place' . (count($seen) > 2 ? 's' : '') . ')' : '') . '</div>';
    if (!empty($first['text'])) {
        echo '<blockquote class="kop-ak-quote">' . esc_html(mb_substr(preg_replace('#https?://\S+#', '[link]', (string) $first['text']), 0, 420)) . '</blockquote>';
    }
    if (!empty($first['archive']) && preg_match('#^https?://#i', (string) $first['archive'])) {
        echo '<div class="kop-ak-muted"><a href="' . esc_url($first['archive']) . '" target="_blank" rel="noopener noreferrer nofollow">Archived copy</a>'
            . ' (the link above is the original address)</div>';
    }
    $src = kop_gdl_sources()[$r['source'] ?? ''] ?? array();
    if (!empty($src['credit'])) {
        echo '<div class="kop-ak-muted">Credit on the record: <a href="' . esc_url($src['credit_url']) . '" target="_blank" rel="noopener">'
            . esc_html($src['credit']) . '</a></div>';
    }
    $also = json_decode((string) $r['also_named'], true) ?: array();
    if ($also) {
        // A SCIAD NET collection whose name is two or more KOP records lists them all and ties none.
        echo '<div class="kop-ak-muted">' . (($r['source'] ?? '') === 'sciad' && (int) $r['facility_id'] === 0 ? 'Same name as these records, pick one: ' : 'Also names: ')
            . esc_html(implode(', ', array_map(function ($a) {
            return $a['name'] . (!empty($a['state']) ? ' (' . $a['state'] . ')' : '');
        }, array_slice($also, 0, 5)))) . '</div>';
    }
    echo '</td></tr>';
}

/** What only Drive Docs needs on top of the kit: its action, and "Add all" on a card. */
function kop_gdl_render_assets() {
    $nonce = wp_create_nonce('kop_drive_docs');
    ?>
    <style>
        .kop-gdl-what { width: 40%; }
        .kop-gdl-what a { word-break: break-word; }
    </style>
    <script>
    (function () {
        if (!window.KOPAdminKit) return;
        var kit = KOPAdminKit.cards({ action: 'kop_gdl_act', nonce: <?php echo wp_json_encode($nonce); ?> });

        // "Add all" on a facility card: the server takes the card's sure matches a batch at a time until none wait.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.kop-gdl-addall');
            if (!btn) return;
            var card = btn.closest('.kop-ak-card');
            var out = card.querySelector('.kop-ak-result');
            var added = 0, problems = 0, last = -1;
            btn.disabled = true;
            (function next() {
                kit.post({ act: 'apply_card', fid: card.dataset.fid, gdl_kind: card.dataset.kind, gdl_src: card.dataset.src, gdl_q: card.dataset.q }).then(function (res) {
                    if (!res.success) { kit.say(out, 'is-err', res.data || 'Failed.'); btn.disabled = false; return; }
                    Object.keys(res.data.results || {}).forEach(function (k) {
                        var tr = card.querySelector('tr[data-key="' + k + '"]');
                        if (res.data.results[k].ok) added++; else problems++;
                        if (tr) tr.classList.add('is-gone');
                    });
                    var left = res.data.left || 0;
                    kit.say(out, 'is-ok', 'Added ' + added + (problems ? ', ' + problems + ' already on file or refused' : '') + (left ? '; ' + left + ' to go...' : '. Undo them on the Added tab.'));
                    if (left && left !== last) { last = left; next(); return; }
                    btn.textContent = 'Done';
                    card.classList.toggle('is-done', !card.querySelector('tr[data-key]:not(.is-gone)'));
                }).catch(function () { kit.say(out, 'is-err', 'Network error after ' + added + ' added; click again to carry on.'); btn.disabled = false; });
            })();
        });
    })();
    </script>
    <?php
}
