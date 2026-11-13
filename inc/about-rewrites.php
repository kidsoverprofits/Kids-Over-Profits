<?php
/**
 * Claude's rewrites of a record's "About" (kop-pipeline scripts/about-rewrite/, BRIEF.md there), reviewed at
 * KOP Tools > About rewrites (inc/about-rewrites-screen.php). Owner, 2026-11-13: Claude writes every About; the hourly
 * AI drafts stopped, and the old review inbox queue (Write-ups) and the deploy seeds are gone.
 *
 * A rewrite is the record's write-up draft (kop_record_writeups, inc/record-writeups.php) with kind 'rewrite':
 *   {sentences: [[{text, sources: [{url, cite}]}]], testimony: [{text, who, where, date, url, via, note, facility_id}],
 *    needs_you: [{ask, choices}], answers: {i: {choice, note}}, research: [...], log, by, at, held, sent_back?}
 * Claude sends it with POST kop/v1/writeups/claude (research key) and reads what to write next with GET.
 *
 * Publishing (kop_ar_publish()): the About goes live with its sentences as written (print sources keep their
 * citation; kop_wu_page_about() reads them), on a facility the approved facts held for it go onto the record
 * (kop_wu_publish()), and the quotes go on the facility published (owner, 2026-11-11), with the credit in "source",
 * the original's address in "url" and, where staff are named, the allegation note. A company's quote goes on the
 * program it is about (facility_id). Undo (kop_ar_undo()) puts back the About, the facts and the quotes as they were.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_ABOUT_REWRITE_BY = 'about-rewrite';

/** [kind, id] for "facility/<slug>" or "operator/<slug>", or null when the page is not found. */
function kop_about_rewrite_resolve($page) {
    if (!preg_match('#^(facility|operator)/([a-z0-9-]+)$#', trim((string) $page, '/'), $m)) return null;
    if ($m[1] === 'facility') {
        $idx = function_exists('kop_facility_pages_index') ? kop_facility_pages_index() : array();
        $id = (int) ($idx['slugs'][$m[2]] ?? 0);
        return $id ? array('facility', $id) : null;
    }
    $idx = function_exists('kop_operator_pages_index') ? kop_operator_pages_index() : array();
    $id = (int) ($idx['slugs'][$m[2]] ?? 0);
    if ($id && !empty($idx['alias_of'][$id])) $id = (int) $idx['alias_of'][$id];
    return $id ? array('operator', $id) : null;
}

/** The sentences as the site's paragraph strings ("Text ([source](url); [source](url))."), for readers that take strings. */
function kop_about_rewrite_strings(array $sentences) {
    $paras = array();
    foreach ($sentences as $para) {
        $bits = array();
        foreach ((array) $para as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') continue;
            $links = array();
            foreach ((array) ($s['sources'] ?? array()) as $src) {
                $u = (string) ($src['url'] ?? '');
                if ($u !== '' && !in_array('[source](' . $u . ')', $links, true)) $links[] = '[source](' . $u . ')';
            }
            if ($links && preg_match('/^(.*?)([.!?]["\x{201D}]?)$/us', $text, $m)) $text = $m[1] . ' (' . implode('; ', $links) . ')' . $m[2];
            elseif ($links) $text .= ' (' . implode('; ', $links) . ')';
            $bits[] = $text;
        }
        if ($bits) $paras[] = implode(' ', $bits);
    }
    return $paras;
}

/** Clean sentences: text and sources only, empty ones dropped. */
function kop_about_rewrite_clean_sentences(array $sentences) {
    $out = array();
    foreach ($sentences as $para) {
        $p = array();
        foreach ((array) $para as $s) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) ($s['text'] ?? '')));
            if ($text === '') continue;
            $src = array();
            foreach ((array) ($s['sources'] ?? array()) as $x) {
                $url = trim((string) ($x['url'] ?? ''));
                if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
                $cite = trim((string) ($x['cite'] ?? ''));
                if ($url !== '' || $cite !== '') $src[] = array('url' => $url, 'cite' => $cite);
            }
            $p[] = array('text' => $text, 'sources' => $src);
        }
        if ($p) $out[] = $p;
    }
    return $out;
}

/** The note lines the page builds its About from now (what the rewrite covers). */
function kop_about_rewrite_notes($kind, $id) {
    if ($kind === 'facility') {
        $stored = kop_facility_load($id, kop_wbf_opts());
        return $stored ? kop_wu_doc_notes($stored['doc']) : array();
    }
    $page = function_exists('kop_operator_page_data') ? kop_operator_page_data($id) : null;
    return is_array($page) ? (array) ($page['notes'] ?? array()) : array();
}

/** One testimony entry as survivorTestimony stores it. */
function kop_about_rewrite_testimony_entry(array $t, $key) {
    $credit = implode(', ', array_filter(array(trim((string) ($t['who'] ?? '')), trim((string) ($t['where'] ?? ''))), 'strlen'));
    $date = trim((string) ($t['date'] ?? ''));
    if (preg_match('/^(undated|unknown|n\.?d\.?)$/i', $date)) $date = '';
    $when = strtotime('1 ' . $date) ?: (preg_match('/^\d{4}$/', $date) ? strtotime($date . '-01-01') : false);
    return array(
        'id'        => $key,
        'text'      => trim((string) $t['text']),
        'source'    => $credit . ($date !== '' ? ($credit !== '' ? ', ' : '') . $date : ''),
        'date'      => $when ? gmdate(preg_match('/^\d{4}$/', $date) ? 'Y' : 'Y-m', $when) : '',
        'url'       => preg_match('#^https?://#i', (string) ($t['url'] ?? '')) ? (string) $t['url'] : '',
        'note'      => trim((string) ($t['note'] ?? '')),
        'movedFrom' => '',
        'publish'   => true,
    );
}

/**
 * Put the record's quotes on its facilities, replacing the ones an earlier version of this rewrite put there
 * (ids "about:<page>:<n>"). Returns [facility id => number written], plus 'skipped' => n.
 */
function kop_about_rewrite_put_testimony($kind, $id, $page, array $quotes) {
    $prefix = 'about:' . trim($page, '/') . ':';
    $by_fid = array();
    $skipped = 0;
    foreach (array_values($quotes) as $n => $t) {
        if (trim((string) ($t['text'] ?? '')) === '') { $skipped++; continue; }   // a recording with no transcript yet
        $fid = (int) ($t['facility_id'] ?? 0) ?: ($kind === 'facility' ? $id : 0);
        if (!$fid) { $skipped++; continue; }
        $by_fid[$fid][] = kop_about_rewrite_testimony_entry($t, $prefix . ($n + 1));
    }
    // Facilities that had this rewrite's quotes before but get none now lose them.
    $prev = (array) get_option('kop_about_rewrites_testimony', array());
    foreach ((array) ($prev[$page] ?? array()) as $fid) if (!isset($by_fid[(int) $fid])) $by_fid[(int) $fid] = array();
    $opts = kop_wbf_opts();
    $done = array();
    foreach ($by_fid as $fid => $entries) {
        kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $entries, $prefix, $opts, &$done) {
            $stored = kop_facility_load($fid, $opts);
            if (!$stored) return;
            $doc = $stored['doc'];
            $list = array_values(array_filter((array) ($doc['survivorTestimony'] ?? array()), function ($e) use ($prefix) {
                return !(is_array($e) && strpos((string) ($e['id'] ?? ''), $prefix) === 0);
            }));
            $doc['survivorTestimony'] = array_merge($list, $entries);
            kop_wbf_save($doc, $opts);
            $done[$fid] = count($entries);
        });
    }
    $prev[$page] = array_keys(array_filter($done));
    update_option('kop_about_rewrites_testimony', $prev, false);
    if ($done && function_exists('kop_record_changed')) kop_record_changed('facility', array_keys($done), array('source' => 'about-rewrite'));
    return $done + array('skipped' => $skipped);
}

/** Put back the write-up a rewrite replaced and take its quotes off. */
function kop_about_rewrite_undo($page) {
    global $wpdb;
    $page = trim((string) $page, '/');
    $rec = kop_about_rewrite_resolve($page);
    if (!$rec) throw new RuntimeException('No page at /' . $page . '/.');
    list($kind, $id) = $rec;
    $key = $kind === 'facility' ? $id : kop_wu_operator_key($id);
    $pub = kop_wu_get($key)['published'];
    if (($pub['by'] ?? '') !== KOP_ABOUT_REWRITE_BY || empty($pub['log'])) throw new RuntimeException('The About on that page is not a rewrite (any more).');
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_wu_log_table() . ' WHERE id = %d', (int) $pub['log']), ARRAY_A);
    $before = $row ? json_decode((string) $row['before_row'], true) : null;
    if (!is_array($before)) throw new RuntimeException('The log entry is gone.');
    kop_wu_put($key, (array) ($before['draft'] ?? array()), (array) ($before['published'] ?? array()));
    $wpdb->update(kop_wu_log_table(), array('undone_at' => current_time('mysql', true)), array('id' => (int) $pub['log']));
    kop_about_rewrite_put_testimony($kind, $id, $page, array());
    if (function_exists('kop_record_changed')) kop_record_changed($kind === 'facility' ? 'facility' : 'operator', array($id), array('source' => 'about-rewrite-undo'));
    $log = (array) get_option('kop_about_rewrites_log', array());
    $log[$page] = array('at' => gmdate('Y-m-d H:i:s'), 'undone' => true) + (array) ($log[$page] ?? array());
    update_option('kop_about_rewrites_log', $log, false);
}

/* ---- One rewrite through the screen (KOP Tools > About rewrites, inc/about-rewrites-screen.php) ---------------- */

/** The write-up row key of a record: the facility id, or 2e9 + the company id (kop_wu_operator_key()). */
function kop_ar_key($kind, $id) {
    return $kind === 'operator' ? kop_wu_operator_key((int) $id) : (int) $id;
}

/** [kind, id] of a write-up row key. */
function kop_ar_from_key($key) {
    $key = (int) $key;
    $base = (int) kop_wu_operator_key(0);
    return $key >= $base ? array('operator', $key - $base) : array('facility', $key);
}

/** "facility/<slug>" or "operator/<slug>" for a record, '' when it has no page. */
function kop_ar_page($kind, $id) {
    $idx = $kind === 'operator'
        ? (function_exists('kop_operator_pages_index') ? kop_operator_pages_index() : array())
        : (function_exists('kop_facility_pages_index') ? kop_facility_pages_index() : array());
    $slug = (string) ($idx['ids'][(int) $id]['slug'] ?? '');
    return $slug !== '' ? $kind . '/' . $slug : '';
}

function kop_ar_name($kind, $id) {
    $idx = $kind === 'operator'
        ? (function_exists('kop_operator_pages_index') ? kop_operator_pages_index() : array())
        : (function_exists('kop_facility_pages_index') ? kop_facility_pages_index() : array());
    $e = $idx['ids'][(int) $id] ?? array();
    return (string) ($e['display'] ?? $e['name'] ?? (($kind === 'operator' ? 'Company #' : 'Record #') . (int) $id));
}

/** A rewrite as Claude sends it, cleaned: {sentences, testimony, needs_you, research, log}. */
function kop_ar_clean_rewrite(array $rw) {
    $sentences = kop_about_rewrite_clean_sentences((array) ($rw['sentences'] ?? array()));
    if (!$sentences) throw new RuntimeException('The rewrite has no sentences.');
    $quotes = array();
    foreach ((array) ($rw['testimony'] ?? array()) as $t) {
        if (!is_array($t)) continue;
        $q = array();
        foreach (array('text', 'who', 'where', 'date', 'url', 'via', 'note') as $k) $q[$k] = trim((string) ($t[$k] ?? ''));
        if (!empty($t['facility_id'])) $q['facility_id'] = (int) $t['facility_id'];
        if ($q['text'] !== '' || $q['who'] !== '') $quotes[] = $q;
    }
    $asks = array();
    foreach ((array) ($rw['needs_you'] ?? array()) as $a) {
        $ask = trim((string) (is_array($a) ? ($a['ask'] ?? '') : $a));
        if ($ask === '') continue;
        $asks[] = array('ask' => $ask, 'choices' => array_values(array_filter(array_map('trim', array_map('strval', (array) (is_array($a) ? ($a['choices'] ?? array()) : array()))), 'strlen')));
    }
    return array(
        'sentences' => $sentences,
        'testimony' => $quotes,
        'needs_you' => $asks,
        'research'  => array_values(array_filter(array_map('trim', array_map('strval', (array) ($rw['research'] ?? array()))), 'strlen')),
        'log'       => trim((string) ($rw['log'] ?? '')),
    );
}

/** Store Claude's rewrite as the record's draft (replacing any draft) and take the record off Claude's list. */
function kop_ar_save_rewrite($kind, $id, array $rw, $by = 'Claude') {
    kop_wu_ensure_tables();
    $key = kop_ar_key($kind, $id);
    if (kop_ar_page($kind, $id) === '') throw new RuntimeException(ucfirst($kind) . " #{$id} has no page.");
    $wu = kop_wu_get($key);
    $clean = kop_ar_clean_rewrite($rw);
    $draft = array('kind' => 'rewrite', 'paragraphs' => kop_about_rewrite_strings($clean['sentences'])) + $clean + array(
        'answers' => array(), 'by' => (string) $by, 'provider' => 'claude', 'at' => gmdate('Y-m-d H:i:s'),
        'held' => $kind === 'facility' ? array_map('kop_wu_item_key', kop_wu_held_rows((int) $id)) : array(),
        'check' => '');
    kop_wu_put($key, $draft, $wu['published']);
    kop_wu_take_from_claude($key);
    return $draft;
}

/** Save the owner's answer to one "Needs you" question ($choice and/or $note; both empty clears it). */
function kop_ar_answer($key, $i, $choice, $note) {
    $wu = kop_wu_get($key);
    $d = $wu['draft'];
    if (($d['kind'] ?? '') !== 'rewrite' || !isset($d['needs_you'][(int) $i])) throw new RuntimeException('That question is gone. Reload the page.');
    $choice = trim((string) $choice);
    $note = trim((string) $note);
    $answers = (array) ($d['answers'] ?? array());
    if ($choice === '' && $note === '') unset($answers[(int) $i]);
    else $answers[(int) $i] = array('choice' => $choice, 'note' => $note, 'at' => gmdate('Y-m-d H:i:s'));
    $d['answers'] = $answers;
    kop_wu_put($key, $d, $wu['published']);
    return $answers[(int) $i] ?? null;
}

/** The rewrite quotes on facilities now for a page: [fid => [entries]] (what undo puts back). */
function kop_ar_current_quotes($page) {
    $prefix = 'about:' . trim($page, '/') . ':';
    $all = (array) get_option('kop_about_rewrites_testimony', array());
    $fids = (array) ($all[$page] ?? array());
    $opts = kop_wbf_opts();
    $out = array();
    foreach ($fids as $fid) {
        $s = kop_facility_load((int) $fid, $opts);
        if (!$s) continue;
        $mine = array_values(array_filter((array) ($s['doc']['survivorTestimony'] ?? array()), function ($e) use ($prefix) {
            return is_array($e) && strpos((string) ($e['id'] ?? ''), $prefix) === 0;
        }));
        if ($mine) $out[(int) $fid] = $mine;
    }
    return $out;
}

/** Put back exactly the quotes a page had ([fid => [entries]]), taking off any others it put on since. */
function kop_ar_restore_quotes($page, array $by_fid) {
    $prefix = 'about:' . trim($page, '/') . ':';
    $all = (array) get_option('kop_about_rewrites_testimony', array());
    $fids = array_unique(array_merge(array_map('intval', (array) ($all[$page] ?? array())), array_map('intval', array_keys($by_fid))));
    $opts = kop_wbf_opts();
    foreach ($fids as $fid) {
        $entries = (array) ($by_fid[$fid] ?? array());
        kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $entries, $prefix, $opts) {
            $s = kop_facility_load($fid, $opts);
            if (!$s) return;
            $doc = $s['doc'];
            $list = array_values(array_filter((array) ($doc['survivorTestimony'] ?? array()), function ($e) use ($prefix) {
                return !(is_array($e) && strpos((string) ($e['id'] ?? ''), $prefix) === 0);
            }));
            $doc['survivorTestimony'] = array_merge($list, $entries);
            kop_wbf_save($doc, $opts);
        });
    }
    $all[$page] = array_values(array_map('intval', array_keys(array_filter($by_fid))));
    update_option('kop_about_rewrites_testimony', $all, false);
    if ($fids && function_exists('kop_record_changed')) kop_record_changed('facility', $fids, array('source' => 'about-rewrite'));
}

/**
 * Publish the record's rewrite: the About goes live with its sentences as written, its quotes go on the facility
 * (published), and on a facility every approved fact held for it goes onto the record (kop_wu_publish()). The
 * quotes the page had before are kept in the log, so Undo puts them back. Returns a message.
 */
function kop_ar_publish($key, $reviewer) {
    global $wpdb;
    list($kind, $id) = kop_ar_from_key($key);
    $wu = kop_wu_get($key);
    $d = $wu['draft'];
    if (($d['kind'] ?? '') !== 'rewrite' || empty($d['sentences'])) throw new RuntimeException('There is no rewrite to publish for this record.');
    $page = kop_ar_page($kind, $id);
    if ($page === '') throw new RuntimeException('This record has no page.');
    $quotes_before = kop_ar_current_quotes($page);
    $record = array('rewrite' => true, 'sentences' => $d['sentences'], 'needs_you' => $d['needs_you'] ?? array(),
        'answers' => $d['answers'] ?? array(), 'research' => $d['research'] ?? array(), 'written_by' => $d['by'] ?? 'Claude');
    if ($kind === 'facility') {
        $res = kop_wu_publish($id, $reviewer);
        $log_id = (int) $res['log'];
        $message = str_replace(' Undo in Recently done takes it all back.', '', $res['message']);
        $pub = kop_wu_get($key)['published'];
        kop_wu_put($key, array(), $record + $pub);
    } else {
        kop_wu_ensure_tables();
        $wpdb->insert(kop_wu_log_table(), array('facility_id' => $key, 'before_row' => wp_json_encode(array('draft' => $d, 'published' => $wu['published'])),
            'items' => wp_json_encode(array()), 'done_by' => mb_substr((string) $reviewer, 0, 60), 'done_at' => current_time('mysql', true)));
        $log_id = (int) $wpdb->insert_id;
        $covered = array_values(array_unique(array_map('kop_wu_note_key', kop_about_rewrite_notes('operator', $id))));
        $GLOBALS['kop_wu_publishing'] = true;
        try {
            kop_wu_put($key, array(), $record + array('paragraphs' => $d['paragraphs'] ?? kop_about_rewrite_strings($d['sentences']), 'covered' => $covered,
                'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'log' => $log_id));
        } finally {
            unset($GLOBALS['kop_wu_publishing']);
        }
        if (function_exists('kop_record_changed')) kop_record_changed('operator', array($id), array('source' => 'writeup'));
        $message = 'The rewrite is the page\'s About now.';
    }
    // Keep the quotes the page had before with the log entry, for Undo.
    $row = $wpdb->get_row($wpdb->prepare('SELECT before_row FROM ' . kop_wu_log_table() . ' WHERE id = %d', $log_id), ARRAY_A);
    $before = $row ? (json_decode((string) $row['before_row'], true) ?: array()) : array();
    $before['quotes'] = $quotes_before;
    $wpdb->update(kop_wu_log_table(), array('before_row' => wp_json_encode($before)), array('id' => $log_id));
    $q = kop_about_rewrite_put_testimony($kind, $id, $page, (array) ($d['testimony'] ?? array()));
    $n = array_sum(array_diff_key($q, array('skipped' => 1)));
    return $message . ($n ? ' ' . $n . ' survivor quote' . ($n === 1 ? ' is' : 's are') . ' on the page.' : '');
}

/** Take back the record's published rewrite: the About, the quotes and (on a facility) the facts, as they were. */
function kop_ar_undo($key, $reviewer) {
    global $wpdb;
    list($kind, $id) = kop_ar_from_key($key);
    $pub = kop_wu_get($key)['published'];
    if (empty($pub['log'])) throw new RuntimeException('Nothing published to take back.');
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_wu_log_table() . ' WHERE id = %d', (int) $pub['log']), ARRAY_A);
    if (!$row || !empty($row['undone_at'])) throw new RuntimeException('That publish was already taken back.');
    $page = kop_ar_page($kind, $id);
    if (($row['done_by'] ?? '') === KOP_ABOUT_REWRITE_BY) {   // one of the first 21, put on by the old seed pass
        kop_about_rewrite_undo($page);
        return 'Taken back: the About is as it was before the rewrite, and its quotes are off.';
    }
    $before = json_decode((string) $row['before_row'], true) ?: array();
    if ($kind === 'facility') {
        $msg = kop_wu_undo((int) $pub['log'], $reviewer);
    } else {
        kop_wu_put($key, (array) ($before['draft'] ?? array()), (array) ($before['published'] ?? array()));
        $wpdb->update(kop_wu_log_table(), array('undone_at' => current_time('mysql', true)), array('id' => (int) $pub['log']));
        if (function_exists('kop_record_changed')) kop_record_changed('operator', array($id), array('source' => 'writeup'));
        $msg = 'Taken back: the About is as it was.';
    }
    if ($page !== '') kop_ar_restore_quotes($page, (array) ($before['quotes'] ?? array()));
    return $msg . ' The rewrite is waiting again.';
}

/** Send the rewrite back to Claude with the owner's note (and answers): the record goes on Claude's list. */
function kop_ar_send_back($key, $note, $by) {
    $wu = kop_wu_get($key);
    $d = $wu['draft'];
    if (($d['kind'] ?? '') !== 'rewrite') throw new RuntimeException('There is no rewrite to send back.');
    $d['sent_back'] = array('note' => trim((string) $note), 'at' => gmdate('Y-m-d H:i:s'), 'by' => (string) $by);
    kop_wu_put($key, $d, $wu['published']);
    kop_wu_send_to_claude($key, $by);
}

/**
 * Every record by where it stands, for the screen and for Claude:
 *   review     a rewrite waiting for the owner (not sent back)
 *   claude     waiting for Claude: sent back, sent by hand, or with approved facts / changes and no rewrite yet
 *   published  a published rewrite, newest first
 * [view => [key => {key, kind, id, why, at}]].
 */
function kop_ar_lists() {
    global $wpdb;
    kop_wu_ensure_tables();
    $out = array('review' => array(), 'claude' => array(), 'published' => array());
    foreach ((array) $wpdb->get_results('SELECT facility_id, draft, published FROM ' . kop_wu_table() . ' ORDER BY id', ARRAY_A) as $r) {
        $key = (int) $r['facility_id'];
        list($kind, $id) = kop_ar_from_key($key);
        $d = json_decode((string) $r['draft'], true) ?: array();
        $p = json_decode((string) $r['published'], true) ?: array();
        if (($d['kind'] ?? '') === 'rewrite' && empty($d['sent_back'])) {
            $out['review'][$key] = array('key' => $key, 'kind' => $kind, 'id' => $id, 'why' => 'rewrite', 'at' => (string) ($d['at'] ?? ''));
        }
        if (!empty($p['rewrite']) || ($p['by'] ?? '') === KOP_ABOUT_REWRITE_BY) {
            $out['published'][$key] = array('key' => $key, 'kind' => $kind, 'id' => $id, 'why' => 'published', 'at' => (string) ($p['at'] ?? ''));
        }
    }
    foreach (kop_wu_claude_queue() as $key => $s) {
        if (isset($out['review'][(int) $key])) continue;
        list($kind, $id) = kop_ar_from_key($key);
        $out['claude'][(int) $key] = array('key' => (int) $key, 'kind' => $kind, 'id' => $id, 'why' => 'sent', 'at' => (string) ($s['at'] ?? ''));
    }
    foreach (kop_wu_todo_ids() as $fid) {
        if (isset($out['review'][(int) $fid]) || isset($out['claude'][(int) $fid])) continue;
        $out['claude'][(int) $fid] = array('key' => (int) $fid, 'kind' => 'facility', 'id' => (int) $fid,
            'why' => kop_wu_held_rows((int) $fid) ? 'facts' : 'changed', 'at' => '');
    }
    uasort($out['published'], function ($a, $b) { return strcmp($b['at'], $a['at']); });
    return $out;
}

/** Claude's list (GET kop/v1/writeups/claude): what to write next and why, with the owner's note and answers on a sent-back one. */
function kop_ar_claude_list() {
    $out = array();
    foreach (kop_ar_lists()['claude'] as $e) {
        $page = kop_ar_page($e['kind'], $e['id']);
        if ($page === '') continue;
        $x = array('kind' => $e['kind'], 'id' => $e['id'], 'packet_id' => ($e['kind'] === 'operator' ? 'o' : 'f') . $e['id'],
            'name' => kop_ar_name($e['kind'], $e['id']), 'page' => $page, 'url' => home_url('/' . $page . '/'), 'why' => $e['why']);
        $d = kop_wu_get($e['key'])['draft'];
        if (($d['kind'] ?? '') === 'rewrite' && !empty($d['sent_back'])) {
            $x['note'] = (string) ($d['sent_back']['note'] ?? '');
            $x['answers'] = array();
            foreach ((array) ($d['needs_you'] ?? array()) as $i => $a) $x['answers'][] = array('ask' => $a['ask'], 'answer' => $d['answers'][$i] ?? null);
            $x['draft'] = array('sentences' => $d['sentences'], 'testimony' => $d['testimony'] ?? array());
        }
        if ($e['kind'] === 'facility') $x['held_facts'] = array_map(function ($h) { $f = kop_wu_fact($h); return array('what' => $f['what'], 'url' => $f['url']); }, kop_wu_held_rows($e['id']));
        $out[] = $x;
    }
    return $out;
}
