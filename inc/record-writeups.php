<?php
/**
 * Write-ups: approved facts wait, seen only by admins, until the record's
 * write-up is approved (owner, 2026-10-15).
 *
 * Approving a fact (Woodbury Facts, HEAL, the wiki, program documents and
 * Fornits staff, incidents and leads, by hand or automatically) no longer
 * writes it onto the facility record. The item is held (status 'held' in its
 * own table, applied_fid = the record) and the record is unchanged. Each
 * record with held facts gets a write-up: its "About" prose rewritten to take
 * in the new facts, drafted by the AI (hourly, or "Write it with AI") from the
 * page's current About and the held facts, each sentence citing its source as
 * "([source](url))". The owner edits and approves it at Review inbox >
 * Write-ups to approve (inc/review-inbox/writeups.php). Approving does
 * everything in one save: every held fact goes onto the record (staff list,
 * incidents, names, closure... exactly what Add used to write), and the text
 * becomes the page's About. Undo (Recently done) takes the facts off again,
 * puts them back on hold and restores the About as it was.
 *
 * Until then the facility page shows the held facts and the draft in a
 * section only administrators see (kop_wu_admin_html()).
 *
 * The published text replaces the notes-built About (inc/profile-notes.php)
 * in kop_facility_page_data() (so the page and the app's 'about' agree); a
 * note added to the record after the write-up was approved is still printed
 * under it until the next write-up takes it in (kop_wu_page_about()).
 *
 * Tables: {prefix}kop_record_writeups (one row per record: the draft and the
 * published text, JSON) and {prefix}kop_record_writeup_log (each approval,
 * with what it wrote, for Undo). Tested by scripts/test-record-writeups.php.
 */

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

if (!function_exists('kop_fact_hold_on')) {
    /** Whether approving a fact holds it for the write-up (always, since 2026-10-15; a test may switch it off). */
    function kop_fact_hold_on() {
        if (isset($GLOBALS['kop_fact_hold_off']) && $GLOBALS['kop_fact_hold_off']) return false;
        return function_exists('apply_filters') ? (bool) apply_filters('kop_fact_hold_on', true) : true;
    }
}

if (defined('KOP_RECORD_WRITEUPS_LOADED')) {
    return;
}
define('KOP_RECORD_WRITEUPS_LOADED', 1);
define('KOP_RECORD_WRITEUPS_DB_VERSION', '1');

function kop_wu_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_record_writeups';
}

function kop_wu_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_record_writeup_log';
}

function kop_wu_ensure_tables() {
    if (get_option('kop_record_writeups_db') === KOP_RECORD_WRITEUPS_DB_VERSION) return;
    global $wpdb;
    if (!function_exists('dbDelta')) {
        if (!defined('ABSPATH') || !is_readable(ABSPATH . 'wp-admin/includes/upgrade.php')) return;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    $charset = $wpdb->get_charset_collate();
    dbDelta('CREATE TABLE ' . kop_wu_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        draft LONGTEXT NULL,
        published LONGTEXT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY facility_id (facility_id)
    ) {$charset};");
    dbDelta('CREATE TABLE ' . kop_wu_log_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        before_row LONGTEXT NULL,
        items LONGTEXT NULL,
        done_by VARCHAR(60) NULL,
        done_at DATETIME NULL,
        undone_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY facility_id (facility_id)
    ) {$charset};");
    update_option('kop_record_writeups_db', KOP_RECORD_WRITEUPS_DB_VERSION);
}

/* ---- The write-up of one record ------------------------------------------- */

/**
 * {id, draft: {paragraphs, check, held, provider, by, at, edited_by}, published:
 * {paragraphs, covered, by, at}} for a record; empty draft/published when none.
 * A merge can leave two rows for one record: the newest counts.
 */
function kop_wu_get($fid) {
    global $wpdb;
    kop_wu_ensure_tables();
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_wu_table() . ' WHERE facility_id = %d ORDER BY id DESC LIMIT 1', (int) $fid), ARRAY_A);
    $draft = $row ? json_decode((string) $row['draft'], true) : null;
    $pub = $row ? json_decode((string) $row['published'], true) : null;
    return array('id' => $row ? (int) $row['id'] : 0, 'draft' => is_array($draft) ? $draft : array(), 'published' => is_array($pub) ? $pub : array());
}

function kop_wu_put($fid, array $draft, array $published) {
    global $wpdb;
    $cur = kop_wu_get($fid);
    $data = array('facility_id' => (int) $fid, 'draft' => $draft ? wp_json_encode($draft) : null,
        'published' => $published ? wp_json_encode($published) : null, 'updated_at' => current_time('mysql', true));
    if ($cur['id']) {
        $wpdb->update(kop_wu_table(), $data, array('id' => $cur['id']));
    } else {
        $wpdb->insert(kop_wu_table(), $data);
    }
}

/** The paragraphs of a write-up from text: one per blank line, spaces tidied. */
function kop_wu_paragraphs_from_text($text) {
    $out = array();
    foreach (preg_split('/\R\s*\R/u', str_replace("\r", '', (string) $text)) as $p) {
        $p = trim(preg_replace('/[ \t]*\n[ \t]*/', ' ', $p));
        if ($p !== '') $out[] = $p;
    }
    return $out;
}

/* ---- Held facts ------------------------------------------------------------ */

/** The held rows of a record: [['src' => 'wbf'|'fornits', 'row' => [...]], ...], oldest first. */
function kop_wu_held_rows($fid) {
    global $wpdb;
    $out = array();
    if (function_exists('kop_wbf_table')) {
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_wbf_table() . " WHERE status = 'held' AND applied_fid = %d ORDER BY reviewed_at, id", (int) $fid), ARRAY_A) as $r) {
            $out[] = array('src' => 'wbf', 'row' => $r);
        }
    }
    if (function_exists('kop_fornits_items_table')) {
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_fornits_items_table() . " WHERE status = 'held' AND applied_fid = %d ORDER BY reviewed_at, id", (int) $fid), ARRAY_A) as $r) {
            $out[] = array('src' => 'fornits', 'row' => $r);
        }
    }
    return $out;
}

/** [facility id => number of held facts], most first. */
function kop_wu_held_counts() {
    global $wpdb;
    $n = array();
    $tables = array();
    if (function_exists('kop_wbf_table')) $tables[] = kop_wbf_table();
    if (function_exists('kop_fornits_items_table')) $tables[] = kop_fornits_items_table();
    foreach ($tables as $t) {
        foreach ((array) $wpdb->get_results("SELECT applied_fid AS fid, COUNT(*) AS n FROM {$t} WHERE status = 'held' AND applied_fid > 0 GROUP BY applied_fid", ARRAY_A) as $r) {
            $n[(int) $r['fid']] = ($n[(int) $r['fid']] ?? 0) + (int) $r['n'];
        }
    }
    arsort($n);
    return $n;
}

/** A held item's key: "wbf:<pkey>" or "fornits:<pkey>". */
function kop_wu_item_key(array $h) {
    return $h['src'] . ':' . $h['row']['pkey'];
}

/**
 * A held item as the write-up reads it: {key, label, what, quote, cite, url}.
 * 'what' is the fact in words, 'cite' and 'url' its source.
 */
function kop_wu_fact(array $h) {
    $r = $h['row'];
    if ($h['src'] === 'wbf') {
        $ev = kop_wbf_evidence($r);
        $first = $ev[0] ?? array();
        $value = kop_wbf_row_value($r);
        $held = json_decode((string) $r['applied'], true);
        if (is_array($held) && isset($held['value'])) $value = $held['value'];
        $what = (string) $r['label'];
        if ($r['op'] === 'add_staff' && is_array($value)) {
            $what = trim((string) ($value['name'] ?? '')) . ': ' . trim((string) ($value['role'] ?? ''), ' :')
                . (trim((string) ($value['pastJobs'] ?? '')) !== '' ? '. Other jobs: ' . trim((string) $value['pastJobs']) : '');
        } elseif ($value !== null && !in_array($r['op'], array('add_staff'), true)) {
            $shown = function_exists('kop_wbf_show_value') ? kop_wbf_show_value($value) : (is_scalar($value) ? (string) $value : '');
            if ($r['op'] === 'set_closed') $shown = 'Closed' . (is_array($value) && !empty($value['endYear']) ? ' in ' . (int) $value['endYear'] : '');
            if ($shown !== '' && stripos($what, $shown) === false) $what .= ': ' . $shown;
        }
        $where = function_exists('kop_wbf_where_it_goes') ? kop_wbf_where_it_goes($r) : $r['path'];
        return array('key' => kop_wu_item_key($h), 'label' => (string) $r['label'], 'where' => $where, 'what' => $what,
            'quote' => trim((string) ($first['quote'] ?? '')), 'cite' => function_exists('kop_wbf_ev_label') && $first ? kop_wbf_ev_label($first) : 'Woodbury Reports',
            'url' => (string) ($first['url'] ?? ''), 'when' => (string) $r['issue_date']);
    }
    $v = json_decode((string) $r['value'], true) ?: array();
    $what = (string) $r['label'];
    if ($r['kind'] === 'staff') {
        $what = trim(($v['person'] ?? '') . ': ' . ($v['role'] ?? '') . (!empty($v['years']) ? ' (' . $v['years'] . ')' : ''), ' :');
    } elseif (!empty($v['summary'])) {
        $what = trim((string) $v['summary']) . (!empty($v['year']) ? ' (' . $v['year'] . ')' : '');
    }
    $kinds = array('staff' => 'Staff', 'incident' => 'Critical incidents', 'lead' => 'Lead kept on the record');
    return array('key' => kop_wu_item_key($h), 'label' => (string) $r['label'], 'where' => $kinds[$r['kind']] ?? $r['kind'],
        'what' => $what, 'quote' => trim((string) $r['quote']),
        'cite' => 'Fornits forum, post by ' . ($r['author'] !== '' ? $r['author'] : 'a member') . (function_exists('kop_fornits_month') && kop_fornits_month($r['post_date']) ? ', ' . kop_fornits_month($r['post_date']) : ''),
        'url' => function_exists('kop_fornits_post_url') ? kop_fornits_post_url($r['topic_id'], $r['post_n']) : '', 'when' => substr((string) $r['post_date'], 0, 7));
}

/**
 * Write one held item onto $doc (by reference) the way its queue's Add did,
 * or a conflict's "Use this value": returns what was done, for Undo. Throws
 * when the record already has it.
 */
function kop_wu_apply_row(array &$doc, array $h) {
    $r = $h['row'];
    $held = json_decode((string) $r['applied'], true);
    $held = is_array($held) ? $held : array();
    if ($h['src'] === 'wbf') {
        if (!empty($held['how']) && kop_wbf_conflict_parts($doc, $r)) {
            return kop_wbf_doc_overwrite($doc, $r, array_key_exists('value', $held) ? $held['value'] : null);
        }
        return kop_wbf_doc_apply($doc, $r);
    }
    if (!empty($held['how'])) {
        $w = kop_fornits_as_wbf($r);
        if ($w && kop_wbf_conflict_parts($doc, $w)) {
            return array('via' => 'wbf') + kop_wbf_doc_overwrite($doc, $w, array_key_exists('value', $held) ? $held['value'] : null);
        }
    }
    return kop_fornits_doc_apply($doc, $r, (string) ($held['target'] ?? ''));
}

/** $doc with every held item of the record written on (items that no longer apply are skipped): the record as it will be. */
function kop_wu_doc_with_held(array $doc, $fid) {
    foreach (kop_wu_held_rows($fid) as $h) {
        $trial = $doc;
        try {
            kop_wu_apply_row($trial, $h);
            $doc = $trial;
        } catch (Throwable $e) {
            // Already there or in conflict now: approving the write-up sorts it out.
        }
    }
    return $doc;
}

/* ---- Notes the published text covers --------------------------------------- */

/** A note line's key: lower case, spaces collapsed. */
function kop_wu_note_key($line) {
    return md5(strtolower(trim(preg_replace('/\s+/u', ' ', (string) $line))));
}

/** The note lines the page builds its About from (kop_facility_page_data()'s $notes). */
function kop_wu_doc_notes(array $doc) {
    $clean = function ($v) {
        if (function_exists('kop_facility_pages_clean_notes')) return kop_facility_pages_clean_notes($v);
        $out = array();
        foreach (is_array($v) ? $v : array($v) as $t) {
            if (is_string($t) && trim($t) !== '' && stripos(trim($t), 'migration:') !== 0) $out[] = trim($t);
        }
        return $out;
    };
    return array_merge($clean($doc['notes'] ?? null), $clean($doc['operatingPeriod']['notes'] ?? null));
}

/* ---- Citations ----------------------------------------------------------- */

/**
 * The AI's numbered markers as the site's citations: "It closed in 2009. [3][5]"
 * -> "It closed in 2009 ([source](a); [source](b))." A number with no link is dropped.
 */
function kop_wu_cite_markers($text, array $urls) {
    $text = preg_replace_callback('/([.!?]["\x{201D}]?)?((?:\s*\[\d+\])+)/u', function ($m) use ($urls) {
        preg_match_all('/\[(\d+)\]/', $m[2], $nums);
        $links = array();
        foreach (array_unique($nums[1]) as $n) {
            $u = (string) ($urls[(int) $n] ?? '');
            if ($u !== '' && !in_array('[source](' . $u . ')', $links, true)) $links[] = '[source](' . $u . ')';
        }
        $cite = $links ? ' (' . implode('; ', $links) . ')' : '';
        return $cite . ($m[1] ?? '');
    }, (string) $text);
    return trim(preg_replace('/\s+([.,;:])/', '$1', $text));
}

/**
 * Published paragraphs in the shape kop_pnotes_build() gives ({paragraphs:
 * [[{text, sources: [{url, cite}]}]]}), so kop_pnotes_html() and the app read
 * them as they read the notes. Each "([source](a); [source](b))" closes a sentence.
 */
function kop_wu_paragraph_sentences($para) {
    $out = array();
    $re = '/\s*\(((?:\s*\[source(?:\s*\d+)?\]\((?:https?:\/\/|\/)[^)\s]+\)\s*;?)+)\)\s*([.!?]["\x{201D}]?)?/u';
    $pos = 0;
    $para = (string) $para;
    if (preg_match_all($re, $para, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $text = trim(substr($para, $pos, $hit[0][1] - $pos)) . (isset($hit[2]) ? $hit[2][0] : '');
            preg_match_all('/\((?:https?:\/\/|\/)[^)\s]+\)/', $hit[1][0], $u);
            $sources = array();
            foreach ($u[0] as $url) $sources[] = array('url' => substr($url, 1, -1), 'cite' => '');
            if (trim($text) !== '') $out[] = array('text' => trim($text), 'sources' => $sources);
            $pos = $hit[0][1] + strlen($hit[0][0]);
        }
    }
    $rest = trim(substr($para, $pos));
    if ($rest !== '') $out[] = array('text' => $rest, 'sources' => array());
    return $out;
}

/**
 * The page's About: the published write-up when there is one, then the notes
 * added since that it does not cover, built as before ($build: fn(lines) =>
 * pnotes about). $about (the notes-built About) when nothing is published.
 */
function kop_wu_page_about($fid, array $notes, $about, callable $build = null) {
    try {
        $wu = kop_wu_get($fid);
    } catch (Throwable $e) {
        return $about;
    }
    $paras = (array) ($wu['published']['paragraphs'] ?? array());
    if (!$paras) return $about;
    $out = array('paragraphs' => array(), 'also' => array(), 'writeup' => true);
    foreach ($paras as $p) {
        $s = kop_wu_paragraph_sentences($p);
        if ($s) $out['paragraphs'][] = $s;
    }
    $covered = array_flip((array) ($wu['published']['covered'] ?? array()));
    $left = array_values(array_filter($notes, function ($l) use ($covered) { return !isset($covered[kop_wu_note_key($l)]); }));
    if ($left && $build) {
        $more = $build($left);
        foreach ((array) ($more['paragraphs'] ?? array()) as $p) $out['paragraphs'][] = $p;
        $out['also'] = (array) ($more['also'] ?? array());
    } elseif (is_array($about)) {
        $out['also'] = (array) ($about['also'] ?? array());
    }
    return $out;
}

/* ---- The AI's draft ------------------------------------------------------- */

function kop_wu_ai($prompt) {
    if (!empty($GLOBALS['kop_wu_ai']) && is_callable($GLOBALS['kop_wu_ai'])) return (string) call_user_func($GLOBALS['kop_wu_ai'], $prompt);
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    return kop_ai_generate_alternating($prompt, array('maxTokens' => 2500, 'temperature' => 0.3));
}

/**
 * The prompt: the page's About as it is (each sentence numbered with its
 * source) and the held facts (numbered on). Returns [prompt, urls by number].
 */
function kop_wu_prompt($fid, array $page = null, array $facts = null) {
    $page = $page ?: (function_exists('kop_facility_page_data') ? kop_facility_page_data($fid) : null);
    if (!$page) throw new RuntimeException('Facility #' . (int) $fid . ' does not exist.');
    $facts = $facts ?? array_map('kop_wu_fact', kop_wu_held_rows($fid));
    $urls = array();
    $n = 0;
    $now = array();
    foreach ((array) ($page['about']['paragraphs'] ?? array()) as $para) {
        foreach ($para as $s) {
            $url = '';
            foreach ((array) ($s['sources'] ?? array()) as $src) {
                $url = (string) ($src['url'] ?? '');
                if ($url !== '') break;
            }
            $n++;
            $urls[$n] = $url;
            $now[] = '[' . $n . '] ' . trim((string) $s['text']);
        }
    }
    $new = array();
    foreach ($facts as $f) {
        $n++;
        $urls[$n] = (string) $f['url'];
        $new[] = '[' . $n . '] ' . $f['where'] . ': ' . $f['what'] . ($f['quote'] !== '' ? ' (the source\'s words: "' . mb_substr($f['quote'], 0, 600) . '")' : '')
            . ' (' . $f['cite'] . ')';
    }
    $status = trim((string) ($page['status'] ?? ''));
    $years = trim(implode(' to ', array_filter(array((string) ($page['start_year'] ?? ''), (string) ($page['end_year'] ?? '')))));
    $prompt = 'You are writing the "About" section of the Kids Over Profits page on ' . $page['name']
        . (!empty($page['place']) ? ' (' . $page['place'] . ')' : '') . ', a program in the troubled teen industry.'
        . ($status !== '' ? ' Status on the record: ' . $status . ($years !== '' ? ', ' . $years : '') . '.' : '') . "\n\n"
        . "Rules:\n"
        . "- Use only the numbered facts below. Never add anything they do not say. Attribute allegations to who made them (\"a former resident wrote on the Fornits forum\").\n"
        . "- Take in every new fact that matters to the program's story; keep what the section already says unless a new fact corrects it.\n"
        . "- Follow the program's life in order: what it was and who ran it, changes of name, owner or place, notable staff, what happened inside, the closing. Group conditions by kind.\n"
        . "- Plain, direct sentences, one claim each, under 25 words. American spelling. No headings, no lists, no emojis, no filler openers, no advice to the reader, no explaining what the reader can see.\n"
        . "- Restraint is restraint (held down, zipped into a bag, strapped): never soften the word.\n"
        . "- Roles: \"a therapist\" for roles many hold, \"the director\" for one-holder titles. No year on a role unless it is a start or an end. No \"Former\" in a role; staff of a closed program in the past tense.\n"
        . "- Never write out a source's name or issue (\"Woodbury Reports, May 2007, p. 20\"); the citation numbers carry the sources.\n"
        . "- After each sentence put the numbers of the facts it rests on, in brackets: [3] or [3][7]. Every sentence needs at least one.\n"
        . "- 1 to 5 short paragraphs; fewer when the facts are few. Leave out what only repeats the page's fact box (ages, size, type) unless it matters to the story.\n\n"
        . ($now ? "What the About section says now:\n" . implode("\n", $now) . "\n\n" : "The page has no About section yet.\n\n")
        . "New facts approved for this page:\n" . implode("\n", $new) . "\n\n"
        . 'Answer with JSON only: {"paragraphs": ["...", "..."], "check": "anything the reviewer should check against the sources (a doubtful reading, facts that disagree), or an empty string"}';
    return array($prompt, $urls);
}

/** Read the AI's answer: {paragraphs (cited), check}. Throws on an answer that is no use. */
function kop_wu_read_answer($answer, array $urls) {
    $s = trim((string) $answer);
    $s = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $s);
    if (($a = strpos($s, '{')) !== false && ($b = strrpos($s, '}')) !== false) $s = substr($s, $a, $b - $a + 1);
    $j = json_decode($s, true);
    if (!is_array($j) || empty($j['paragraphs']) || !is_array($j['paragraphs'])) throw new RuntimeException('The AI did not answer with paragraphs.');
    $paras = array();
    foreach ($j['paragraphs'] as $p) {
        $p = kop_wu_cite_markers(trim((string) $p), $urls);
        if ($p !== '') $paras[] = $p;
    }
    if (!$paras) throw new RuntimeException('The AI wrote nothing usable.');
    return array('paragraphs' => $paras, 'check' => trim((string) ($j['check'] ?? '')));
}

/**
 * Ask the AI for the record's write-up and store it as the draft (replacing
 * any draft). Returns the draft.
 */
function kop_wu_write($fid, $by = 'ai') {
    $held = kop_wu_held_rows($fid);
    if (!$held) throw new RuntimeException('No approved facts are waiting for this record.');
    list($prompt, $urls) = kop_wu_prompt($fid, null, array_map('kop_wu_fact', $held));
    try {
        $out = kop_wu_read_answer(kop_wu_ai($prompt), $urls);
    } catch (RuntimeException $e) {
        $out = kop_wu_read_answer(kop_wu_ai($prompt . "\n\nYour last answer was refused: " . $e->getMessage() . ' Answer again with the JSON only.'), $urls);
    }
    $wu = kop_wu_get($fid);
    $draft = array('paragraphs' => $out['paragraphs'], 'check' => $out['check'], 'held' => array_map('kop_wu_item_key', $held),
        'provider' => (string) ($GLOBALS['kop_ai_last_provider'] ?? ''), 'by' => $by, 'at' => gmdate('Y-m-d H:i:s'));
    kop_wu_put($fid, $draft, $wu['published']);
    return $draft;
}

/** Held facts the draft was not written with (approved since): their keys. */
function kop_wu_new_since_draft($fid, array $draft = null, array $held = null) {
    $draft = $draft ?? kop_wu_get($fid)['draft'];
    $held = $held ?? kop_wu_held_rows($fid);
    $had = array_flip((array) ($draft['held'] ?? array()));
    $out = array();
    foreach ($held as $h) {
        if (!isset($had[kop_wu_item_key($h)])) $out[] = kop_wu_item_key($h);
    }
    return $out;
}

/** A reviewer's text saved as the draft. */
function kop_wu_save_draft($fid, $text, $by) {
    $paras = kop_wu_paragraphs_from_text($text);
    $wu = kop_wu_get($fid);
    $draft = $wu['draft'];
    if ($paras === (array) ($draft['paragraphs'] ?? array())) return false;
    $draft['paragraphs'] = $paras;
    $draft['edited_by'] = (string) $by;
    $draft['at'] = gmdate('Y-m-d H:i:s');
    if (!isset($draft['held'])) $draft['held'] = array_map('kop_wu_item_key', kop_wu_held_rows($fid));
    kop_wu_put($fid, $draft, $wu['published']);
    return true;
}

/* ---- Approving ------------------------------------------------------------ */

/**
 * Approve the record's write-up: every held fact goes onto the record in one
 * save, the draft (or $text) becomes the page's About. A fact the record has
 * by now is filed as rejected ("already there"); one that disagrees with it
 * goes back to its queue's waiting list marked as a conflict. Returns
 * {message, log, added, already, conflicts}.
 */
function kop_wu_publish($fid, $reviewer, $text = null) {
    global $wpdb;
    $fid = (int) $fid;
    kop_wu_ensure_tables();
    $wu = kop_wu_get($fid);
    if ($text !== null && trim((string) $text) !== '') {
        kop_wu_save_draft($fid, $text, $reviewer);
        $wu = kop_wu_get($fid);
    }
    $paras = (array) ($wu['draft']['paragraphs'] ?? array());
    if (!$paras) throw new RuntimeException('There is no write-up to approve yet. Use "Write it with AI", or type it in the box and save.');
    $opts = kop_wbf_opts();
    $result = array('added' => 0, 'already' => 0, 'conflicts' => 0, 'log' => 0, 'closed' => false);
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $reviewer, $paras, $wu, $opts, $wpdb, &$result) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) throw new RuntimeException("Facility #{$fid} does not exist.");
        $doc = $stored['doc'];
        $items = array();
        $now = current_time('mysql', true);
        foreach (kop_wu_held_rows($fid) as $h) {
            $trial = $doc;
            try {
                $done = kop_wu_apply_row($trial, $h);
                $doc = $trial;
                $items[] = array('src' => $h['src'], 'pkey' => $h['row']['pkey'], 'done' => $done, 'held' => (string) $h['row']['applied']);
                if (($h['row']['op'] ?? '') === 'set_closed' || (($done['op'] ?? '') === 'set_closed')) $result['closed'] = true;
            } catch (RuntimeException $e) {
                $table = $h['src'] === 'wbf' ? kop_wbf_table() : kop_fornits_items_table();
                $conflict = $h['src'] === 'wbf' ? kop_wbf_conflict($doc, $h['row']) : kop_fornits_conflict($doc, $h['row']);
                if ($conflict !== '') {
                    $back = array('status' => 'pending', 'applied' => null, 'applied_fid' => 0);
                    if ($h['src'] === 'wbf') $back += array('conflict' => $conflict, 'auto' => 0);
                    $wpdb->update($table, $back, array('pkey' => $h['row']['pkey']));
                    $result['conflicts']++;
                } else {
                    $wpdb->update($table, array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => $e->getMessage())),
                        'reviewed_at' => $now), array('pkey' => $h['row']['pkey']));
                    $result['already']++;
                }
            }
        }
        if ($items) kop_wbf_save($doc, $opts);
        // What the text covers: every note the record has now, the held facts' included.
        $after = kop_facility_load($fid, $opts);
        $covered = array_values(array_unique(array_map('kop_wu_note_key', kop_wu_doc_notes($after ? $after['doc'] : $doc))));
        $wpdb->insert(kop_wu_log_table(), array('facility_id' => $fid, 'before_row' => wp_json_encode(array('draft' => $wu['draft'], 'published' => $wu['published'])),
            'items' => wp_json_encode($items), 'done_by' => mb_substr((string) $reviewer, 0, 60), 'done_at' => $now));
        $log = (int) $wpdb->insert_id;
        foreach ($items as $it) {
            $table = $it['src'] === 'wbf' ? kop_wbf_table() : kop_fornits_items_table();
            $wpdb->update($table, array('status' => 'applied', 'applied' => wp_json_encode($it['done'] + array('writeup' => $log))), array('pkey' => $it['pkey']));
        }
        kop_wu_put($fid, array(), array('paragraphs' => $paras, 'covered' => $covered, 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'log' => $log));
        $result['added'] = count($items);
        $result['log'] = $log;
    });
    if ($result['closed']) do_action('kop_facility_status_changed', $fid);
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array($fid), array('source' => 'writeup'));
    $bits = array('The write-up is the page\'s About now' . ($result['added'] ? ', and ' . $result['added'] . ' held fact' . ($result['added'] === 1 ? ' is' : 's are') . ' on the record' : ''));
    if ($result['already']) $bits[] = $result['already'] . ' the record had by now went to Rejected';
    if ($result['conflicts']) $bits[] = $result['conflicts'] . ' that disagree' . ($result['conflicts'] === 1 ? 's' : '') . ' with the record went back to Conflicts';
    $result['message'] = implode('; ', $bits) . '. Undo in Recently done takes it all back.';
    return $result;
}

/** Take back an approval: the facts come off the record and are held again, the About is as it was. */
function kop_wu_undo($log_id, $reviewer) {
    global $wpdb;
    kop_wu_ensure_tables();
    $log = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_wu_log_table() . ' WHERE id = %d', (int) $log_id), ARRAY_A);
    if (!$log) throw new RuntimeException('That approval is not in the log.');
    if (!empty($log['undone_at'])) throw new RuntimeException('That approval was already undone.');
    $fid = (int) $log['facility_id'];
    $wu = kop_wu_get($fid);
    if ((int) ($wu['published']['log'] ?? 0) !== (int) $log['id']) {
        throw new RuntimeException('A later write-up of this record was approved since. Undo that one first.');
    }
    $items = json_decode((string) $log['items'], true) ?: array();
    $before = json_decode((string) $log['before_row'], true) ?: array();
    $opts = kop_wbf_opts();
    $closed = false;
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $items, $opts, $wpdb, &$closed) {
        if (!$items) return;
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) throw new RuntimeException("Facility #{$fid} does not exist.");
        $doc = $stored['doc'];
        foreach (array_reverse($items) as $it) {
            if ($it['src'] === 'wbf') {
                kop_wbf_doc_undo($doc, (array) $it['done']);
            } else {
                kop_fornits_doc_undo($doc, (array) $it['done']);
            }
            if ((($it['done']['op'] ?? '') === 'set_closed')) $closed = true;
        }
        kop_wbf_save($doc, $opts);
        foreach ($items as $it) {
            $table = $it['src'] === 'wbf' ? kop_wbf_table() : kop_fornits_items_table();
            $wpdb->update($table, array('status' => 'held', 'applied' => (string) $it['held']), array('pkey' => $it['pkey'], 'status' => 'applied'));
        }
    });
    kop_wu_put($fid, (array) ($before['draft'] ?? array()), (array) ($before['published'] ?? array()));
    $wpdb->update(kop_wu_log_table(), array('undone_at' => current_time('mysql', true)), array('id' => (int) $log['id']));
    if ($closed) do_action('kop_facility_status_changed', $fid);
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array($fid), array('source' => 'writeup'));
    return 'Undone: ' . count($items) . ' fact' . (count($items) === 1 ? ' is' : 's are') . ' off the record and held again, and the About is as it was.';
}

/* ---- The hourly drafts ----------------------------------------------------- */

/**
 * Draft write-ups for up to $limit records with held facts that have no draft,
 * or whose AI draft predates facts approved since (a draft a person edited is
 * left alone). Returns {written, errors, left}.
 */
function kop_wu_run_batch($limit = 3) {
    $out = array('written' => 0, 'errors' => array(), 'left' => 0);
    $todo = array();
    foreach (array_keys(kop_wu_held_counts()) as $fid) {
        $wu = kop_wu_get($fid);
        if (!empty($wu['draft']['edited_by'])) continue;
        if (empty($wu['draft']['paragraphs']) || kop_wu_new_since_draft($fid, $wu['draft'])) $todo[] = $fid;
    }
    $out['left'] = max(0, count($todo) - $limit);
    $fails = 0;
    foreach (array_slice($todo, 0, $limit) as $fid) {
        try {
            kop_wu_write($fid);
            $out['written']++;
            $fails = 0;
        } catch (Throwable $e) {
            $out['errors'][] = '#' . $fid . ': ' . $e->getMessage();
            if (++$fails >= 2) break;
        }
    }
    return $out;
}

/** The last hourly run in words ("Last hourly run, 3:05 pm: 0 drafts written; stopped on: ..."), or ''. */
function kop_wu_last_run_line() {
    $r = get_option('kop_wu_last_run');
    if (!is_array($r) || empty($r['at'])) return '';
    $when = function_exists('wp_date') ? wp_date('M j, g:i a', (int) $r['at']) : gmdate('M j, H:i', (int) $r['at']) . ' UTC';
    $n = (int) ($r['written'] ?? 0);
    $errors = (array) ($r['errors'] ?? array());
    return 'Last hourly run, ' . $when . ': ' . $n . ' draft' . ($n === 1 ? '' : 's') . ' written'
        . ($errors ? '; stopped on: ' . implode(' | ', array_map(function ($e) { return mb_substr((string) $e, 0, 200); }, array_slice($errors, 0, 2))) : '')
        . ((int) ($r['left'] ?? 0) ? '; ' . (int) $r['left'] . ' more still to write' : '') . '.';
}

if (function_exists('add_action')) {
    add_action('init', function () {
        if (!wp_next_scheduled('kop_wu_hourly')) wp_schedule_event(time() + 1200, 'hourly', 'kop_wu_hourly');
    });
    add_action('kop_wu_hourly', function () {
        kop_wu_ensure_tables();
        try {
            $r = kop_wu_run_batch(3);
        } catch (Throwable $e) {
            $r = array('written' => 0, 'errors' => array($e->getMessage()), 'left' => 0);
        }
        // Shown on Review inbox > Write-ups to approve, so a run that fails every hour is seen (kop_wu_last_run_line()).
        update_option('kop_wu_last_run', array('at' => time()) + $r, false);
    });
}

/* ---- The admin-only section on the facility page ------------------------------ */

/** Where the record's write-up card is: the review inbox's queue. */
function kop_wu_inbox_url() {
    $url = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-admin-submissions.php') : '';
    if ($url === '') $url = home_url('/admin-submissions/');
    return add_query_arg('type', 'writeups', $url);
}

/** The draft as HTML, cited like the About. */
function kop_wu_paragraphs_html(array $paras) {
    $about = array('paragraphs' => array());
    foreach ($paras as $p) {
        $s = kop_wu_paragraph_sentences($p);
        if ($s) $about['paragraphs'][] = $s;
    }
    return function_exists('kop_pnotes_html') ? kop_pnotes_html($about) : '<p>' . esc_html(implode("\n\n", $paras)) . '</p>';
}

/**
 * "Waiting for the write-up": the facts approved for this record and the
 * draft, for administrators only ('' for everyone else, and when nothing waits).
 */
function kop_wu_admin_html($fid, $can = null) {
    if ($can === null) $can = function_exists('current_user_can') && current_user_can('manage_options');
    if (!$can) return '';
    try {
        $held = kop_wu_held_rows($fid);
        $wu = kop_wu_get($fid);
    } catch (Throwable $e) {
        return '';
    }
    $draft = (array) ($wu['draft']['paragraphs'] ?? array());
    if (!$held && !$draft) return '';
    $html = '<section class="kop-fp-section kop-wu-section" id="writeup-waiting"><h2>Waiting for the write-up</h2>'
        . '<p class="kop-fp-count">Only administrators see this. ' . count($held) . ' approved fact' . (count($held) === 1 ? ' is' : 's are')
        . ' held for this page. Nothing here is on the public page until the write-up is approved at <a href="' . esc_url(kop_wu_inbox_url()) . '">Review inbox &gt; Write-ups to approve</a>.</p>';
    if ($draft) {
        $new = kop_wu_new_since_draft($fid, $wu['draft'], $held);
        $html .= '<h3 class="kop-fp-subhead">Draft write-up</h3>' . kop_wu_paragraphs_html($draft);
        if (!empty($wu['draft']['check'])) $html .= '<p class="kop-fp-count"><strong>To check:</strong> ' . esc_html($wu['draft']['check']) . '</p>';
        if ($new) $html .= '<p class="kop-fp-count">' . count($new) . ' fact' . (count($new) === 1 ? ' was' : 's were') . ' approved after this draft was written.</p>';
    } else {
        $html .= '<p class="kop-fp-count">No draft yet: the AI writes one within the hour, or use "Write it with AI" on the card.</p>';
    }
    if ($held) {
        $html .= '<h3 class="kop-fp-subhead">Approved facts held for it</h3><ul class="kop-wu-facts">';
        foreach ($held as $h) {
            $f = kop_wu_fact($h);
            $src = $f['url'] !== '' ? ' <a href="' . esc_url($f['url']) . '" target="_blank" rel="noopener nofollow">source</a>' : '';
            $html .= '<li><strong>' . esc_html($f['where']) . ':</strong> ' . esc_html($f['what']) . $src
                . ($f['quote'] !== '' ? '<br><span class="kop-fp-count">"' . esc_html(mb_substr($f['quote'], 0, 400)) . '"</span>' : '') . '</li>';
        }
        $html .= '</ul>';
    }
    return $html . '</section>';
}
