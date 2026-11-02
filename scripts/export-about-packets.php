<?php
/**
 * Packets for rewriting a record's "About" prose (kop-pipeline scripts/about-rewrite/, BRIEF.md there):
 * one JSON file per record with every fact the page holds, numbered, each with its source link, and what
 * the page prints now. Read-only against the production mirror (tmp/prod.sqlite).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/export-about-packets.php --ids=f9607,o7 --out=<dir>
 *
 * Facts: the research notes (their own words, citation cut off and resolved to a link, as
 * kop_pnotes_build() resolves it), the fact box, staff, incidents, news, lawsuits and deaths
 * (kop_wu_record_facts()), the timeline's events (kop_ptl_facility() / the operator page's timeline) and,
 * on a company, its programs and its written history.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'ids:', 'out:'));
if (empty($args['ids']) || empty($args['out'])) {
    fwrite(STDERR, "Usage: --ids=f9607,o7 --out=<dir>\n");
    exit(2);
}
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/record-writeups.php';
$out_dir = rtrim($args['out'], '/\\');
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

$t0 = microtime(true);
$say = function ($s) use ($t0) { fwrite(STDERR, sprintf("[%6.1fs] %s\n", microtime(true) - $t0, $s)); };

/** Every note line in its own words, with the link its citation resolves to. */
function kop_xap_notes(array $notes, array $known = array()) {
    $out = array();
    foreach ($notes as $line) {
        if (!is_string($line)) continue;
        foreach (array_values(array_filter(array_map('trim', preg_split('/\R/', $line)), 'strlen')) as $p) {
            $kind = kop_pnotes_kind($p);
            if (in_array($kind, array('skip', 'also', 'forum'), true)) continue;
            $label = '';
            $body = $p;
            if (preg_match('/^([A-Z][A-Za-z\/]*(?:\s[A-Za-z\/]+){0,3}):\s+(?=\S)/', $p, $m)) {
                $label = $m[1];
                $body = substr($p, strlen($m[0]));
            }
            $c = kop_pnotes_split_cite($body);
            $url = $c['url'];
            if ($url === '' && $c['cite'] !== '' && isset($known[$c['cite']])) $url = $known[$c['cite']];
            if ($url === '' && $c['cite'] !== '' && function_exists('kop_facility_pages_cite_url')) $url = (string) kop_facility_pages_cite_url($c['cite']);
            $text = trim(($label !== '' ? $label . ': ' : '') . $c['text']);
            if ($text !== '') $out[] = array('kind' => $kind === 'rail' ? 'fact box' : 'note', 'text' => $text, 'url' => $url);
        }
    }
    return $out;
}

/** Timeline events as facts: "2016 (August): Sold to X. Lead. Detail." */
function kop_xap_timeline(array $years) {
    $out = array();
    foreach ($years as $y) {
        foreach ((array) ($y['events'] ?? array()) as $e) {
            $words = trim(implode(' ', array_filter(array_merge(array((string) $e['title'], (string) $e['lead']), array_map('strval', (array) $e['detail'])))));
            $names = array();
            foreach ((array) ($e['items'] ?? array()) as $it) if (!empty($it['name'])) $names[] = $it['name'] . (!empty($it['meta']) ? ' (' . $it['meta'] . ')' : '');
            if ($names) $words .= ' [' . implode('; ', array_slice($names, 0, 12)) . ']';
            $url = '';
            foreach ((array) ($e['sources'] ?? array()) as $s) if (!empty($s['url'])) { $url = $s['url']; break; }
            $out[] = array('kind' => 'timeline', 'text' => trim(($e['when'] !== '' ? $e['when'] : $e['year']) . ': ' . strip_tags($words)), 'url' => $url);
        }
    }
    return $out;
}

function kop_xap_before($about) {
    if (!is_array($about)) return '';
    $paras = array();
    foreach ((array) ($about['paragraphs'] ?? array()) as $para) {
        $paras[] = trim(implode(' ', array_map(function ($s) { return trim((string) $s['text']); }, $para)));
    }
    return implode("\n\n", $paras);
}

global $wpdb;
foreach (array_filter(array_map('trim', explode(',', $args['ids']))) as $key) {
    $kind = $key[0];
    $id = (int) substr($key, 1);
    $say("$key: page data");
    $facts = array();
    if ($kind === 'f') {
        $page = kop_facility_page_data($id);
        if (!$page) { $say("$key: no page"); continue; }
        $say("$key: notes + record");
        $facts = kop_xap_notes((array) $page['notes']);
        foreach (kop_wu_record_facts($page) as $f) {
            $facts[] = array('kind' => strtolower(strtok($f['where'], ',')), 'text' => ($f['where'] !== '' ? $f['where'] . ': ' : '') . $f['what'], 'url' => $f['url']);
        }
        $say("$key: timeline");
        $facts = array_merge($facts, kop_xap_timeline(kop_ptl_facility($page)));
        $head = array('name' => $page['name'], 'place' => (string) ($page['place'] ?? ''), 'status' => (string) ($page['status'] ?? ''),
            'years' => trim(implode(' to ', array_filter(array((string) ($page['start_year'] ?? ''), (string) ($page['end_year'] ?? ''))))),
            'operator' => (string) ($page['operator']['name'] ?? ''), 'url' => str_replace('https://example.test/', 'https://kidsoverprofits.org/', (string) ($page['url'] ?? '')));
        $history = array();
    } else {
        $page = kop_operator_page_data($id);
        if (!$page) { $say("$key: no page"); continue; }
        $facts = kop_xap_notes((array) $page['notes']);
        foreach ((array) ($page['facts'] ?? array()) as $f) {
            if (is_array($f) && !empty($f['label']) && is_scalar($f['value'] ?? null)) $facts[] = array('kind' => 'fact box', 'text' => $f['label'] . ': ' . strip_tags((string) $f['value']), 'url' => '');
        }
        // Its programs: the page lists them, so no link (never cite our own pages).
        foreach ((array) ($page['facilities'] ?? array()) as $p) {
            if (!is_array($p)) continue;
            $bits = array_filter(array((string) ($p['name'] ?? '') . (!empty($p['now_name']) && $p['now_name'] !== $p['name'] ? ' (now ' . $p['now_name'] . ')' : ''), (string) ($p['place'] ?? ''), (string) ($p['status'] ?? ''), (string) ($p['years'] ?? '')), 'strlen');
            $facts[] = array('kind' => 'program', 'text' => 'Program: ' . implode(', ', $bits), 'url' => '');
        }
        $facts = array_merge($facts, kop_xap_timeline((array) ($page['timeline'] ?? array())));
        // The written history, draft or published (a draft is hidden from the harness's visitor).
        $op_doc = kop_operator_pages_decode((string) $wpdb->get_var($wpdb->prepare('SELECT json_data FROM ' . $wpdb->prefix . 'kop_operators WHERE id = %d', $id)));
        $history = array_values(array_filter((array) ($op_doc['history'] ?? array()), 'is_string'));
        $head = array('name' => $page['name'], 'place' => '', 'status' => '', 'years' => '', 'operator' => '', 'url' => str_replace('https://example.test/', 'https://kidsoverprofits.org/', (string) ($page['url'] ?? '')),
            'summary' => is_string($page['summary'] ?? null) ? $page['summary'] : '');
    }
    // One fact, said the same way twice: once.
    $seen = array();
    $numbered = array();
    foreach ($facts as $f) {
        $k = strtolower(preg_replace('/\W+/u', ' ', $f['text']));
        if ($f['text'] === '' || isset($seen[$k]) || preg_match('/:\s*$/', $f['text'])) continue;
        // The harness's home is example.test: links to our own files are the live site's.
        $f['url'] = str_replace('https://example.test/', 'https://kidsoverprofits.org/', (string) $f['url']);
        $seen[$k] = true;
        $numbered[] = array('n' => count($numbered) + 1) + $f;
    }
    $packet = array('id' => $key, 'kind' => $kind === 'f' ? 'facility' : 'company') + $head + array(
        'before' => kop_xap_before($page['about'] ?? null), 'history' => $history, 'facts' => $numbered,
        // The notes the rewrite was written from: a note added later still prints under it (kop_wu_page_about()).
        'covered' => array_values(array_unique(array_map('kop_wu_note_key', array_filter((array) $page['notes'], 'is_string')))));
    file_put_contents("$out_dir/$key.json", json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $say("$key: " . count($numbered) . ' facts');
}
