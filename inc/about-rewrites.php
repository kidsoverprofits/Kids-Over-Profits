<?php
/**
 * Claude's rewrites of a record's "About" (kop-pipeline scripts/about-rewrite/, pilot in docs/kop-about-rewrite-pilot/),
 * shipped as seeds and applied on deploy by the template pass (kop_apply_template_assignments(), inc/admin.php).
 *
 * seeds/about-rewrites/<kind>-<slug>.json, one per record (written by kop-pipeline scripts/about-rewrite/export-seeds.py):
 *   {"page": "facility/aspen-ranch-ut" | "operator/teen-challenge", "name": "Aspen Ranch", "version": "<hash>",
 *    "sentences": [[{"text": "...", "sources": [{"url": "https://...", "cite": "Outlet, date"}]}, ...], ...],
 *    "testimony": [{"id": "...", "text": "...", "who": "Former student", "where": "WWASP Survivors",
 *                   "date": "March 2014", "url": "https://...", "via": "...", "facility_id": 100753}]}
 *
 * The About becomes the record's published write-up (kop_record_writeups; read by kop_wu_page_about() on the facility
 * and company page), with "sentences" kept as written so print sources (no address) keep their citation. Held facts
 * are NOT released (kop_wu_publish() would release them). The notes the page has now are "covered"; notes added later
 * show under it, as with any write-up. The previous write-up goes to the write-up log first (kop_wu_log_table()), so
 * kop_about_rewrite_undo() puts it back.
 *
 * Testimony (owner, 2026-11-11): quoted word for word and published at once (publish: true) into the facility's
 * survivorTestimony, with the credit (who, where, date) in "source" and the original's address in "url". A company's
 * quote goes to the program it is about ("facility_id"); one without a program is skipped and logged.
 *
 * Undo: list the page in seeds/about-rewrites/_undo.json (["facility/aspen-ranch-ut", ...]); the next pass puts back
 * what was there and leaves the page alone while it stays listed.
 *
 * Applied once per version. A record whose write-up was changed after the rewrite went on (published.by is no longer
 * 'about-rewrite') is left alone and logged in option kop_about_rewrites_log.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_ABOUT_REWRITE_BY = 'about-rewrite';

function kop_about_rewrite_seeds() {
    $dir = trailingslashit(get_stylesheet_directory()) . 'seeds/about-rewrites';
    $out = array();
    foreach ((array) glob($dir . '/*.json') as $path) {
        if (basename($path)[0] === '_') continue;
        $spec = json_decode((string) file_get_contents($path), true);
        if (is_array($spec) && !empty($spec['page']) && !empty($spec['sentences']) && !empty($spec['version'])) $out[basename($path)] = $spec;
    }
    ksort($out);
    return $out;
}

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

/** Apply every seed whose version is not on its record yet. Returns a summary for the template pass. */
function kop_apply_about_rewrites() {
    $summary = array('applied' => array(), 'undone' => array(), 'skipped' => array());
    if (!function_exists('kop_wu_get') || !function_exists('kop_wbf_opts')) return $summary;
    $log = (array) get_option('kop_about_rewrites_log', array());
    $undo_path = trailingslashit(get_stylesheet_directory()) . 'seeds/about-rewrites/_undo.json';
    $undo = file_exists($undo_path) ? array_map(function ($p) { return trim((string) $p, '/'); }, (array) json_decode((string) file_get_contents($undo_path), true)) : array();
    foreach ($undo as $page) {
        if (!empty($log[$page]['undone'])) continue;
        try {
            kop_about_rewrite_undo($page);
            $summary['undone'][] = $page;
        } catch (Throwable $e) {
            $summary['skipped'][] = $page . ' (undo): ' . $e->getMessage();
        }
        $log = (array) get_option('kop_about_rewrites_log', array());
    }
    foreach (kop_about_rewrite_seeds() as $file => $spec) {
        $page = trim((string) $spec['page'], '/');
        if (in_array($page, $undo, true)) continue;
        try {
            $rec = kop_about_rewrite_resolve($page);
            if (!$rec) throw new RuntimeException('no page at /' . $page . '/');
            list($kind, $id) = $rec;
            $key = $kind === 'facility' ? $id : kop_wu_operator_key($id);
            $wu = kop_wu_get($key);
            $pub = $wu['published'];
            if (($pub['seed'] ?? '') === $spec['version']) continue;
            if (isset($pub['seed']) && ($pub['by'] ?? '') !== KOP_ABOUT_REWRITE_BY) throw new RuntimeException('its write-up was changed after the rewrite went on');
            $sentences = kop_about_rewrite_clean_sentences((array) $spec['sentences']);
            if (!$sentences) throw new RuntimeException('no sentences');
            global $wpdb;
            kop_wu_ensure_tables();
            // Undo goes back to the record as it was before any rewrite: a new version keeps the first one's log entry.
            if (($pub['by'] ?? '') === KOP_ABOUT_REWRITE_BY && !empty($pub['log'])) {
                $log_id = (int) $pub['log'];
            } else {
                $wpdb->insert(kop_wu_log_table(), array('facility_id' => $key, 'before_row' => wp_json_encode(array('draft' => $wu['draft'], 'published' => $pub)),
                    'items' => wp_json_encode(array()), 'done_by' => KOP_ABOUT_REWRITE_BY, 'done_at' => current_time('mysql', true)));
                $log_id = (int) $wpdb->insert_id;
            }
            $covered = array_values(array_unique(array_map('kop_wu_note_key', kop_about_rewrite_notes($kind, $id))));
            $GLOBALS['kop_wu_publishing'] = true;   // our own save is not a change to write up again
            try {
                kop_wu_put($key, $wu['draft'], array('paragraphs' => kop_about_rewrite_strings($sentences), 'sentences' => $sentences, 'covered' => $covered,
                    'by' => KOP_ABOUT_REWRITE_BY, 'at' => gmdate('Y-m-d H:i:s'), 'seed' => (string) $spec['version'], 'log' => $log_id));
                $quotes = kop_about_rewrite_put_testimony($kind, $id, $page, (array) ($spec['testimony'] ?? array()));
            } finally {
                unset($GLOBALS['kop_wu_publishing']);
            }
            if (function_exists('kop_record_changed')) kop_record_changed($kind === 'facility' ? 'facility' : 'operator', array($id), array('source' => 'about-rewrite'));
            $summary['applied'][] = $page;
            $log[$page] = array('at' => gmdate('Y-m-d H:i:s'), 'version' => $spec['version'], 'id' => $id, 'quotes' => $quotes);
        } catch (Throwable $e) {
            $summary['skipped'][] = $page . ': ' . $e->getMessage();
            $log[$page] = array('at' => gmdate('Y-m-d H:i:s'), 'version' => $spec['version'], 'skipped' => $e->getMessage());
        }
    }
    update_option('kop_about_rewrites_log', $log, false);
    return $summary;
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
