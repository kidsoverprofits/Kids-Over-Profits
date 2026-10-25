<?php
/**
 * Research projects: a volunteer project (inc/volunteer-projects.php) whose
 * items are questions to find the answer to, not review inbox items to
 * recommend on. Each item is one question, usually about one program record
 * ("When did Rocky Mountain Academy in Bonners Ferry, ID close?"); the
 * volunteer writes what they found with the links they found it at, or says
 * they could not find it and where they looked.
 *
 * Kinds (kop_volr_kinds()): records that lack something (closed with no
 * closing year, no opening year, status unknown, no news on file), picked
 * from facilities_v2 and narrowed by a state or a word, or questions typed
 * one per line. A project's source is 'research:<kind>'; a record already in
 * an open project of that kind, or already answered there, is not picked again.
 *
 * Every link a volunteer gives goes to the review inbox as they save it
 * (news, lawsuit, bill or website, the same inserts and duplicate rules as
 * kop/v1/mobile/submit), with the question and their answer as the note, so a
 * source is never lost. Nothing on a record changes: on the project's page an
 * admin reads each answer, opens the record to change it, and marks the
 * answer Used or Not needed (which the volunteer sees on "Your answers").
 *
 * Table: {prefix}kop_vol_answers. REST: kop/v1/volunteer/answer.
 * Tested by scripts/test-volunteer-projects.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_VOLR_DB_VERSION = '1';
const KOP_VOLR_MAX_LINKS = 6;
const KOP_VOLR_MAX_CLAIMS = 15;

function kop_volr_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_vol_answers';
}

function kop_volr_ensure_tables() {
    if (get_option('kop_vol_answers_db') === KOP_VOLR_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = kop_volr_table();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        project_id BIGINT UNSIGNED NOT NULL,
        item_key VARCHAR(191) NOT NULL,
        volunteer_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        answer TEXT NULL,
        found TINYINT NOT NULL DEFAULT 1,
        links LONGTEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT '',
        decided_by VARCHAR(60) NOT NULL DEFAULT '',
        decided_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY one_each (project_id, item_key, volunteer_id),
        KEY status (status)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('kop_vol_answers_db', KOP_VOLR_DB_VERSION);
}

/* ---- Kinds ---------------------------------------------------------------------- */

/**
 * The research kinds: a title, the question (per record), what a good answer
 * and a weak one look like, minutes per item, the link kind a found link
 * starts as, and the facilities_v2 condition that picks the records ('' for
 * typed questions).
 */
function kop_volr_kinds() {
    return array(
        'closing-year' => array(
            'title'    => 'When did these programs close?',
            'question' => 'When did this program close? Give the year (the month too if you find it) and the link where you found it.',
            'yes'      => '"Closed in March 2009" with a link to a news story, a state licensing record or the program\'s own closing notice.',
            'no'       => 'A year with no link, or a guess from when its website stopped working. If you only find a hint, say so and give the link anyway.',
            'minutes'  => 5, 'link_kind' => 'article',
            'where'    => "f.status = 'Closed' AND f.end_year IS NULL",
        ),
        'opening-year' => array(
            'title'    => 'When did these programs open?',
            'question' => 'When did this program open (or first take young people)? Give the year and the link where you found it.',
            'yes'      => '"Founded in 1998" from an old newspaper story, a state licence date, a nonprofit filing or an archived copy of its website.',
            'no'       => 'When a new owner bought it, or a year with no link. Say what the year is (opened, licensed, bought) if you are not sure.',
            'minutes'  => 5, 'link_kind' => 'article',
            'where'    => 'f.start_year IS NULL',
        ),
        'status' => array(
            'title'    => 'Are these programs still open?',
            'question' => 'Is this program still open? If it closed, when? Give the link that shows it.',
            'yes'      => '"Still open: the state licence was renewed in 2026" with a link to the licence lookup, or "Closed in 2015" with a news story.',
            'no'       => 'A working website alone (closed programs often keep theirs), or a review site. Say what you found and how sure you are.',
            'minutes'  => 4, 'link_kind' => 'website',
            'where'    => "f.status = 'Unknown'",
        ),
        'news' => array(
            'title'    => 'Find news coverage of these programs',
            'question' => 'Find news stories about this program: abuse, injuries, deaths, lawsuits, inspections, closures, or the people who ran it. Paste each link.',
            'yes'      => 'A local paper\'s story about a staff member charged, a TV report on a state investigation, an obituary of a young person who died there.',
            'no'       => 'The program\'s own press releases, paid "best programs" lists or sports results. If you find nothing, say where you searched.',
            'minutes'  => 6, 'link_kind' => 'article',
            'where'    => 'NOT EXISTS (SELECT 1 FROM news_facility_links l WHERE l.facility_id = f.id)',
        ),
        'custom' => array(
            'title'    => 'Research questions',
            'question' => 'Find the answer to each question. Cite every piece of information: write each fact on its own line with the link to the page that says it.',
            'yes'      => 'Each fact on its own line, each with its own link: "Closed in June 2009" with the newspaper story that says so, "Renamed Pine View in 2006" with the state licence record.',
            'no'       => 'A fact with no link, one link meant to cover several facts it does not say, or what you remember or guess. If you cannot find it, say where you looked.',
            'minutes'  => 5, 'link_kind' => 'website',
            'where'    => '',
        ),
    );
}

/** What a found link can be, and where it goes in the inbox. */
function kop_volr_link_kinds() {
    return array('article' => 'News story', 'lawsuit' => 'Court record', 'legislation' => 'Bill or law', 'website' => 'Other page');
}

function kop_volr_is(array $p) {
    return strpos((string) $p['source'], 'research:') === 0;
}

function kop_volr_kind_of(array $p) {
    $k = substr((string) $p['source'], 9);
    return kop_volr_kinds()[$k] ?? kop_volr_kinds()['custom'];
}

/* ---- Making a research project ---------------------------------------------------------- */

/** Records of this kind already in an open project or answered (not "not needed") in any project of it. */
function kop_volr_taken_keys($source) {
    global $wpdb;
    kop_volp_ensure_tables();
    kop_volr_ensure_tables();
    $keys = kop_volp_claimed_keys($source);
    $rows = $wpdb->get_col($wpdb->prepare('SELECT a.item_key FROM ' . kop_volr_table() . ' a JOIN ' . kop_volp_table()
        . " p ON p.id = a.project_id WHERE p.source = %s AND a.status <> 'dismissed' AND a.found = 1", (string) $source));
    foreach ((array) $rows as $k) $keys[(string) $k] = true;
    return $keys;
}

/** Where a record is, in words: "Bonners Ferry, ID". */
function kop_volr_place(array $r) {
    return implode(', ', array_filter(array(trim((string) ($r['city'] ?? '')), trim((string) ($r['state'] ?? '')))));
}

/**
 * The items of a new research project: [key => [title, detail]]. Records for
 * the record kinds (narrowed by a 2-letter state or a word in the name),
 * the typed lines for 'custom'.
 */
function kop_volr_pick($kind_key, $search, $size, $questions = '') {
    $kinds = kop_volr_kinds();
    if (!isset($kinds[$kind_key])) throw new RuntimeException('Unknown kind of research.');
    $kind = $kinds[$kind_key];
    $picked = array();
    if ($kind['where'] === '') {
        $n = 0;
        foreach (preg_split('/\R/u', (string) $questions) as $line) {
            $q = trim(preg_replace('/^\s*(?:[-*\x{2022}]|\d+[.)])\s*/u', '', $line));
            if ($q === '') continue;
            $picked['q' . (++$n)] = array(mb_substr($q, 0, 255), array('question' => mb_substr($q, 0, 1000)));
            if (count($picked) >= $size) break;
        }
        if (!$picked) throw new RuntimeException('Type the questions, one on each line.');
        return $picked;
    }
    global $wpdb;
    $skip = kop_volr_taken_keys('research:' . $kind_key);
    $where = $kind['where'];
    $args = array();
    $search = trim((string) $search);
    if (preg_match('/^[A-Za-z]{2}$/', $search)) {
        $where .= ' AND f.state = %s';
        $args[] = strtoupper($search);
    } elseif ($search !== '') {
        $where .= ' AND f.name LIKE %s';
        $args[] = '%' . $wpdb->esc_like($search) . '%';
    }
    $sql = "SELECT f.id, f.name, f.city, f.state, f.status, f.start_year, f.end_year FROM facilities_v2 f WHERE $where ORDER BY f.state, f.name";
    $rows = (array) $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A);
    foreach ($rows as $r) {
        $key = 'f' . (int) $r['id'];
        if (isset($skip[$key]) || trim((string) $r['name']) === '') continue;
        $place = kop_volr_place($r);
        $picked[$key] = array(mb_substr($r['name'] . ($place !== '' ? ' (' . $place . ')' : ''), 0, 255), array('facility_id' => (int) $r['id']));
        if (count($picked) >= $size) break;
    }
    if (!$picked) {
        throw new RuntimeException($search !== '' ? 'No records of that kind match "' . $search . '" (or they are all in other projects already).'
            : 'No records of that kind are left that are not already in a project.');
    }
    return $picked;
}

/* ---- What a volunteer sees ------------------------------------------------------------ */

/** One question as the volunteer page gets it, with the record's facts read fresh. */
function kop_volr_item(array $p, array $row, $vid) {
    $detail = json_decode((string) ($row['detail'] ?? ''), true);
    $detail = is_array($detail) ? $detail : array();
    $kind = kop_volr_kind_of($p);
    $out = array(
        'source' => 'research', 'project_id' => (int) $p['id'], 'key' => (string) $row['item_key'],
        'title' => (string) $row['title'], 'question' => (string) ($detail['question'] ?? $p['question']),
        'record' => null, 'facts' => array(), 'link_kind' => $kind['link_kind'], 'answer' => null,
    );
    $fid = (int) ($detail['facility_id'] ?? 0);
    if ($fid) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT id, name, city, state, status, start_year, end_year FROM facilities_v2 WHERE id = %d', $fid), ARRAY_A);
        if (!$r) return null;   // merged away or deleted
        $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '';
        $out['title'] = $r['name'];
        $out['record'] = array('name' => (string) $r['name'], 'url' => preg_match('#^https?://#', $url) ? $url : '');
        $years = trim(($r['start_year'] ? $r['start_year'] : '?') . ' to ' . ($r['end_year'] ? $r['end_year'] : ($r['status'] === 'Open' ? 'now' : '?')));
        foreach (array('Where' => kop_volr_place($r), 'Status on our record' => (string) $r['status'], 'Years on our record' => $years === '? to ?' ? 'none yet' : $years) as $l => $v) {
            if ($v !== '') $out['facts'][] = array('label' => $l, 'value' => $v);
        }
    }
    if ($vid) {
        $a = kop_volr_get_answer((int) $p['id'], $row['item_key'], $vid);
        if ($a) $out['answer'] = kop_volr_public_answer($a);
    }
    return $out;
}

function kop_volr_get_answer($pid, $key, $vid) {
    global $wpdb;
    kop_volr_ensure_tables();
    $a = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_volr_table() . ' WHERE project_id = %d AND item_key = %s AND volunteer_id = %d', (int) $pid, (string) $key, (int) $vid), ARRAY_A);
    return $a ?: null;
}

function kop_volr_links_of(array $a) {
    $l = json_decode((string) ($a['links'] ?? ''), true);
    return is_array($l) ? $l : array();
}

function kop_volr_public_answer(array $a) {
    return array('answer' => (string) $a['answer'], 'found' => (bool) (int) $a['found'], 'links' => kop_volr_links_of($a), 'status' => (string) $a['status']);
}

/** The next unanswered questions of a research project for this volunteer. */
function kop_volr_items(array $p, $vid, $limit, array $skip) {
    $mine = array_fill_keys(array_map('strval', $skip), true);
    if ($vid) {
        global $wpdb;
        kop_volr_ensure_tables();
        foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_volr_table() . ' WHERE project_id = %d AND volunteer_id = %d', (int) $p['id'], (int) $vid)) as $k) {
            $mine[(string) $k] = true;
        }
    }
    $items = array();
    foreach (kop_volp_item_rows($p['id'], true) as $r) {
        if ((int) $r['gone'] || isset($mine[$r['item_key']])) continue;
        $it = kop_volr_item($p, $r, $vid);
        if (!$it) { kop_volp_mark_gone($p['id'], $r['item_key']); continue; }
        $items[] = $it;
        if (count($items) >= $limit) break;
    }
    return $items;
}

/** Keys this volunteer has answered in a project. */
function kop_volr_answered_keys($pid, $vid) {
    global $wpdb;
    kop_volr_ensure_tables();
    $q = $vid
        ? $wpdb->prepare('SELECT DISTINCT item_key FROM ' . kop_volr_table() . ' WHERE project_id = %d AND volunteer_id = %d', (int) $pid, (int) $vid)
        : $wpdb->prepare('SELECT DISTINCT item_key FROM ' . kop_volr_table() . ' WHERE project_id = %d', (int) $pid);
    return array_fill_keys(array_map('strval', (array) $wpdb->get_col($q)), true);
}

/* ---- Answering ------------------------------------------------------------------------ */

/** Clean the links a volunteer gave: [{url, kind, title}], at most KOP_VOLR_MAX_LINKS, no repeats. */
function kop_volr_clean_links($links, $default_kind) {
    $out = array();
    $kinds = kop_volr_link_kinds();
    foreach ((array) $links as $l) {
        if (is_string($l)) $l = array('url' => $l);
        if (!is_array($l)) continue;
        $url = trim((string) ($l['url'] ?? ''));
        if ($url === '') continue;
        if (!preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $url)) throw new RuntimeException('"' . mb_substr($url, 0, 80) . '" is not a web address (it should start with https://).');
        $url = esc_url_raw($url, array('http', 'https'));
        if ($url === '' || isset($out[$url])) continue;
        $kind = (string) ($l['kind'] ?? '');
        $out[$url] = array('url' => $url, 'kind' => isset($kinds[$kind]) ? $kind : $default_kind, 'title' => mb_substr(trim((string) ($l['title'] ?? '')), 0, 300));
        if (count($out) >= KOP_VOLR_MAX_LINKS) break;
    }
    return array_values($out);
}

/**
 * The facts a volunteer found, each with the link that says it (owner, 2026-10-25: every piece of information is
 * cited): [{claim, url, kind, quote}]. A fact without a link, or a link without its fact, is refused by its number.
 */
function kop_volr_clean_claims($claims, $default_kind) {
    $out = array();
    $kinds = kop_volr_link_kinds();
    $n = 0;
    foreach ((array) $claims as $c) {
        if (!is_array($c)) continue;
        $claim = trim(mb_substr(str_replace("\r\n", "\n", (string) ($c['claim'] ?? '')), 0, 1000));
        $url = trim((string) ($c['url'] ?? ''));
        $quote = trim(mb_substr(str_replace("\r\n", "\n", (string) ($c['quote'] ?? '')), 0, 1000));
        if ($claim === '' && $url === '' && $quote === '') continue;
        $n++;
        if ($claim === '') throw new RuntimeException('Fact ' . $n . ' has a link but no fact: write what that page says.');
        if ($url === '') throw new RuntimeException('Fact ' . $n . ' has no link. Every fact needs the link to the page where you read it; if you cannot find one, leave the fact out.');
        if (!preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $url)) throw new RuntimeException('The link for fact ' . $n . ' is not a web address (it should start with https://).');
        $kind = (string) ($c['kind'] ?? '');
        $out[] = array('claim' => $claim, 'url' => esc_url_raw($url, array('http', 'https')), 'kind' => isset($kinds[$kind]) ? $kind : $default_kind,
            'quote' => $quote, 'title' => '');
        if (count($out) >= KOP_VOLR_MAX_CLAIMS) break;
    }
    return $out;
}

/** Cited facts as the answer text admins read: one line each, "- fact ([source](url))", the quote under it. */
function kop_volr_claims_text(array $claims) {
    $lines = array();
    foreach ($claims as $c) {
        $lines[] = '- ' . preg_replace('/\s+/', ' ', $c['claim']) . ' ([source](' . $c['url'] . '))';
        if (($c['quote'] ?? '') !== '') $lines[] = '  - It says: "' . preg_replace('/\s+/', ' ', trim($c['quote'], " \"\xE2\x80\x9C\xE2\x80\x9D")) . '"';
    }
    return implode("\n", $lines);
}

/**
 * Save (or change) a volunteer's answer to one question. $found false = they
 * could not find it ($answer says where they looked). New links go to the
 * review inbox now. Returns the answer as the page shows it.
 * $claims (the volunteer page always sends them): the facts found, each with its link; the answer text is built
 * from them and each link is stored with its fact. Without $claims, $answer + $links as before.
 */
function kop_volr_answer(array $vol, $pid, $key, $answer, $links, $found = true, $claims = null) {
    $p = kop_volp_get($pid);
    if (!$p || !kop_volr_is($p) || !kop_volp_for_volunteer($p, (int) $vol['id'])) throw new RuntimeException('This project is finished or not yours. Thank you!');
    $row = null;
    foreach (kop_volp_item_rows($pid, true) as $r) if ($r['item_key'] === (string) $key) $row = $r;
    if (!$row || (int) $row['gone']) throw new RuntimeException('That question is not in this project any more.');
    $answer = trim(mb_substr(str_replace("\r\n", "\n", (string) $answer), 0, 4000));
    $kind = kop_volr_kind_of($p);
    if ($found && $claims !== null) {
        $links = kop_volr_clean_claims($claims, $kind['link_kind']);
        if (!$links) throw new RuntimeException('Add each fact you found with the link where it says it, or press "Could not find it".');
        $answer = trim(mb_substr(kop_volr_claims_text($links) . ($answer !== '' ? "\n\n" . $answer : ''), 0, 8000));
    } else {
        $links = kop_volr_clean_links($links, $kind['link_kind']);
    }
    if ($found && $answer === '' && !$links) throw new RuntimeException('Write what you found, or press "Could not find it".');
    if (!$found && $answer === '') throw new RuntimeException('Say where you looked, so nobody searches the same places again.');
    kop_vol_rate_check((int) $vol['id']);

    global $wpdb;
    kop_volr_ensure_tables();
    $t = kop_volr_table();
    $now = current_time('mysql', true);
    $old = kop_volr_get_answer($pid, $key, (int) $vol['id']);
    if ($old && $old['status'] !== '') throw new RuntimeException('An admin has already read this answer.');
    // Links sent with an earlier save keep what happened to them; new ones go to the inbox now.
    $sent = array();
    foreach ($old ? kop_volr_links_of($old) : array() as $l) $sent[$l['url']] = $l['sent'] ?? '';
    $item = kop_volr_item($p, $row, 0);
    // A cited link goes to the inbox once, its note naming the facts it supports.
    $supports = array();
    foreach ($links as $l) if (($l['claim'] ?? '') !== '') $supports[$l['url']][] = $l['claim'] . (($l['quote'] ?? '') !== '' ? ' ("' . $l['quote'] . '")' : '');
    foreach ($links as &$l) {
        if (!isset($sent[$l['url']])) {
            $sent[$l['url']] = kop_volr_send_source($vol, $p, $item ?: array('title' => $row['title'], 'question' => $p['question']), $l,
                isset($supports[$l['url']]) ? 'This page says: ' . implode('; ', $supports[$l['url']]) : $answer);
        }
        $l['sent'] = $sent[$l['url']];
    }
    unset($l);
    $data = array('title' => mb_substr((string) $row['title'], 0, 255), 'answer' => $answer, 'found' => $found ? 1 : 0,
        'links' => wp_json_encode($links), 'updated_at' => $now);
    if ($old) {
        $wpdb->update($t, $data, array('id' => (int) $old['id']));
    } else {
        $wpdb->insert($t, $data + array('project_id' => (int) $pid, 'item_key' => (string) $key, 'volunteer_id' => (int) $vol['id'], 'created_at' => $now));
    }
    return kop_volr_public_answer(kop_volr_get_answer($pid, $key, (int) $vol['id']));
}

/** Take back an answer no admin has read. */
function kop_volr_withdraw(array $vol, $pid, $key) {
    global $wpdb;
    kop_volr_ensure_tables();
    $wpdb->query($wpdb->prepare('DELETE FROM ' . kop_volr_table() . " WHERE project_id = %d AND item_key = %s AND volunteer_id = %d AND status = ''",
        (int) $pid, (string) $key, (int) $vol['id']));
    return array('message' => 'Taken back.');
}

/**
 * Send one found link to the review inbox, as kop/v1/mobile/submit would:
 * 'news review', 'lawsuit review', 'legislation review', 'website review',
 * 'already held', or 'not sent' (the records database unreachable). Tests
 * swap the sender with the kop_volr_send_source filter.
 */
function kop_volr_send_source(array $vol, array $p, array $item, array $link, $answer) {
    $pre = apply_filters('kop_volr_send_source', null, $vol, $p, $item, $link);
    if ($pre !== null) return (string) $pre;
    $labels = array('article' => 'news review', 'lawsuit' => 'lawsuit review', 'legislation' => 'legislation review', 'website' => 'website review');
    try {
        if (!function_exists('kop_ext_load_record_libs') || !function_exists('kop_seed_pdo')) return 'not sent';
        kop_ext_load_record_libs();
        $pdo = kop_seed_pdo();
        if (!$pdo) return 'not sent';
        $rec = is_array($item['record'] ?? null) ? (string) $item['record']['name'] : '';
        $sp = array(
            'type' => $link['kind'], 'url' => $link['url'], 'title' => $link['title'] !== '' ? $link['title'] : $link['url'], 'facility' => $rec,
            'notes' => 'Found by volunteer ' . $vol['name'] . ' for the project "' . $p['title'] . '". Question: ' . ($item['question'] ?? $p['question'])
                . ($rec !== '' ? ' (about ' . $item['title'] . ')' : ' (' . ($item['title'] ?? '') . ')') . ($answer !== '' ? "\n\nTheir answer: " . $answer : ''),
        );
        if (kop_ext_find_duplicates($pdo, $sp)) return 'already held';
        $submitter = mb_substr($vol['name'] . ' (volunteer)', 0, 255);
        $note = kop_ext_note($sp);
        switch ($link['kind']) {
            case 'article': $id = kop_ext_insert_news($pdo, $sp, $submitter, $note); break;
            case 'lawsuit': $id = kop_ext_insert_lawsuit($pdo, $sp, $submitter, $note); break;
            case 'legislation': $id = kop_ext_insert_legislation($pdo, $sp, $submitter, $note); break;
            default: $id = kop_ext_insert_website($sp, $submitter, $note);
        }
        return is_wp_error($id) || !$id ? 'not sent' : $labels[$link['kind']];
    } catch (Throwable $e) {
        error_log('kop volunteer source not sent: ' . $e->getMessage());
        return 'not sent';
    }
}

/* ---- Progress and the admin's reading ----------------------------------------------------- */

/** {total, done, open (answers not read yet), decided, last} like kop_volp_progress(). */
function kop_volr_progress(array $p) {
    global $wpdb;
    kop_volr_ensure_tables();
    $rows = kop_volp_item_rows($p['id']);
    $answers = (array) $wpdb->get_results($wpdb->prepare('SELECT item_key, status, updated_at FROM ' . kop_volr_table() . ' WHERE project_id = %d', (int) $p['id']), ARRAY_A);
    $by = array();
    foreach ($answers as $a) $by[$a['item_key']][] = $a;
    $out = array('total' => count($rows), 'done' => 0, 'open' => 0, 'decided' => 0, 'last' => '');
    foreach ($rows as $r) {
        $as = $by[$r['item_key']] ?? array();
        if ($as || (int) $r['gone']) $out['done']++;
        if (!$as) continue;
        $open = false;
        foreach ($as as $a) {
            if ($a['status'] === '') $open = true;
            if ($a['updated_at'] > $out['last']) $out['last'] = $a['updated_at'];
        }
        $out[$open ? 'open' : 'decided']++;
    }
    return $out;
}

/** Mark an answer read: 'used' or 'dismissed' (not needed), or '' to put it back. */
function kop_volr_decide($answer_id, $status) {
    if (!in_array($status, array('used', 'dismissed', ''), true)) throw new RuntimeException('Unknown choice.');
    global $wpdb;
    kop_volr_ensure_tables();
    $u = wp_get_current_user();
    $n = $wpdb->update(kop_volr_table(), array('status' => $status, 'decided_by' => $status !== '' && $u && $u->exists() ? $u->user_login : '',
        'decided_at' => $status !== '' ? current_time('mysql', true) : null), array('id' => (int) $answer_id));
    if (!$n) throw new RuntimeException('No such answer, or nothing changed.');
}

/** A volunteer's answers for "Your answers", newest first. */
function kop_volr_mine($vid) {
    global $wpdb;
    kop_volr_ensure_tables();
    $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT a.*, p.title AS project_title FROM ' . kop_volr_table() . ' a LEFT JOIN ' . kop_volp_table()
        . ' p ON p.id = a.project_id WHERE a.volunteer_id = %d ORDER BY a.updated_at DESC LIMIT 200', (int) $vid), ARRAY_A);
    $out = array();
    foreach ($rows as $r) {
        $out[] = array('kind' => 'answer', 'project_id' => (int) $r['project_id'], 'key' => $r['item_key'], 'title' => $r['title'],
            'source_label' => (string) ($r['project_title'] ?? 'Research'), 'answer' => (string) $r['answer'], 'found' => (bool) (int) $r['found'],
            'links' => count(kop_volr_links_of($r)), 'at' => $r['updated_at'] . 'Z', 'outcome' => $r['status']);
    }
    return $out;
}

/* ---- REST ----------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/volunteer/answer', array(
        'methods' => 'POST', 'callback' => 'kop_volr_rest_answer',
        'permission_callback' => function (WP_REST_Request $req) {
            $who = kop_vol_rest_who($req);
            return is_wp_error($who) ? $who : true;
        },
    ));
});

function kop_volr_rest_answer(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        if (empty($who['vol'])) throw new RuntimeException('Admins see this page as a preview; answering is for volunteers.');
        $pid = (int) $req->get_param('project_id');
        $key = (string) $req->get_param('key');
        if ($req->get_param('withdraw')) return kop_volr_withdraw($who['vol'], $pid, $key);
        $links = $req->get_param('links');
        $claims = $req->get_param('claims');
        $found = !$req->get_param('not_found');
        // The page sends each fact with its link; a found answer without them is refused (every fact is cited).
        $answer = kop_volr_answer($who['vol'], $pid, $key, wp_unslash((string) $req->get_param('answer')), is_array($links) ? wp_unslash($links) : array(),
            $found, $found ? (is_array($claims) ? wp_unslash($claims) : array()) : null);
        return array('answer' => $answer, 'message' => 'Thanks. Your answer is saved; an admin reads every one.');
    });
}

/* ---- Admin: one research project ------------------------------------------------------- */

/** An answer for the admin: each cited fact with its source link, what kind of page and where it went, and the quote; else the text. */
function kop_volr_admin_answer_html(array $a, array $links, array $kinds) {
    $cited = array_values(array_filter($links, function ($l) { return ($l['claim'] ?? '') !== ''; }));
    if (!$cited) return '<span style="white-space:pre-wrap">' . esc_html((string) $a['answer']) . '</span>';
    $out = '<ul style="margin:4px 0 0 18px;list-style:disc">';
    foreach ($cited as $l) {
        $out .= '<li>' . esc_html($l['claim']) . ' (<a href="' . esc_url($l['url']) . '" target="_blank" rel="noopener noreferrer">source</a>, <span class="description">'
            . esc_html(($kinds[$l['kind']] ?? $l['kind']) . (($l['sent'] ?? '') !== '' ? ', ' . $l['sent'] : '')) . '</span>)'
            . (($l['quote'] ?? '') !== '' ? '<br><span class="description">It says: "' . esc_html($l['quote']) . '"</span>' : '') . '</li>';
    }
    $out .= '</ul>';
    $rest = trim(substr((string) $a['answer'], strlen(kop_volr_claims_text($cited))));
    return $out . ($rest !== '' ? '<span style="white-space:pre-wrap">' . esc_html($rest) . '</span>' : '');
}

function kop_volr_admin_project(array $p, $nonce, array $names) {
    global $wpdb;
    kop_volr_ensure_tables();
    $rows = kop_volp_item_rows($p['id'], true);
    $answers = (array) $wpdb->get_results($wpdb->prepare('SELECT a.*, v.name FROM ' . kop_volr_table() . ' a LEFT JOIN ' . kop_vol_table()
        . ' v ON v.id = a.volunteer_id WHERE a.project_id = %d ORDER BY a.updated_at', (int) $p['id']), ARRAY_A);
    $by = array();
    foreach ($answers as $a) $by[$a['item_key']][] = $a;
    $kinds = kop_volr_link_kinds();
    $status_words = array('' => 'Not read yet', 'used' => 'Used', 'dismissed' => 'Not needed');
    $btn = function ($aid, $status, $label, $class = 'button') use ($nonce, $p) {
        return '<form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="kop_volp_do" value="decide"><input type="hidden" name="kop_volp_id" value="' . (int) $p['id'] . '">'
            . '<input type="hidden" name="kop_volr_answer" value="' . (int) $aid . '"><input type="hidden" name="kop_volr_status" value="' . esc_attr($status) . '">'
            . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form> ';
    };
    ?>
    <h3>Their answers</h3>
    <p style="max-width:760px">Nothing on a record changes by itself: open the record, change it with its pencil, then mark the answer <em>Used</em>.
        Every link they gave is already in the review inbox (the line under it says where). <em>Not needed</em> lets the record be asked about again in a later project.</p>
    <table class="widefat striped" style="max-width:1100px">
        <thead><tr><th style="width:28%">Question</th><th>Answer</th><th style="width:190px"></th></tr></thead><tbody>
        <?php foreach ($rows as $r) :
            $detail = json_decode((string) ($r['detail'] ?? ''), true);
            $fid = is_array($detail) ? (int) ($detail['facility_id'] ?? 0) : 0;
            $url = $fid && function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '';
            $as = $by[$r['item_key']] ?? array();
            ?>
            <tr>
                <td><?php echo $url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($r['title']) . '</a>' : esc_html($r['title']); ?>
                    <?php echo (int) $r['gone'] ? '<br><span class="description">The record is gone (merged or deleted).</span>' : ''; ?></td>
                <td><?php if (!$as) echo '<span class="description">Not answered yet</span>';
                    foreach ($as as $a) :
                        $links = kop_volr_links_of($a); ?>
                        <div style="margin:0 0 8px">
                            <?php echo (int) $p['volunteer_id'] ? '' : '<strong>' . esc_html((string) $a['name']) . ':</strong> '; ?>
                            <?php echo (int) $a['found'] ? '' : '<em>Could not find it.</em> '; ?>
                            <?php echo kop_volr_admin_answer_html($a, $links, $kinds);
                            // Cited facts carry their own links; only an older answer's loose links are listed under it.
                            $links = array_values(array_filter($links, function ($l) { return ($l['claim'] ?? '') === ''; })); ?>
                            <?php if ($links) : ?><ul style="margin:4px 0 0 18px;list-style:disc">
                                <?php foreach ($links as $l) : ?>
                                    <li><a href="<?php echo esc_url($l['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($l['title'] !== '' ? $l['title'] : $l['url']); ?></a>
                                        <span class="description">(<?php echo esc_html(($kinds[$l['kind']] ?? $l['kind']) . ($l['sent'] ?? '' ? ', ' . $l['sent'] : '')); ?>)</span></li>
                                <?php endforeach; ?></ul><?php endif; ?>
                            <span class="description"><?php echo esc_html($status_words[$a['status']] ?? $a['status']); ?></span>
                        </div>
                    <?php endforeach; ?></td>
                <td><?php foreach ($as as $a) {
                        if ($a['status'] === '') echo $btn($a['id'], 'used', 'Used', 'button button-primary') . $btn($a['id'], 'dismissed', 'Not needed');
                        else echo $btn($a['id'], '', 'Mark not read', 'button-link');
                    } ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
