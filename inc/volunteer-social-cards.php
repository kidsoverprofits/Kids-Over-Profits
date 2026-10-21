<?php
/**
 * The weekly roundup's post ideas (inc/volunteer-social.php): kop_vsoc_cards()
 * reads {prefix}kop_review_log between two times (approved, not undone) and
 * turns each item worth a post into a card that carries only what the public
 * site shows once it is approved:
 *
 *   closure (closure / apply)            the facility page, the article's quote
 *   lawsuit (lawsuit / approve)          /lawsuits/#lawsuit-<id>, the case summary
 *   bill (legislation / approve)         /legislation/#bill-<id>, the bill summary
 *   finding (highlight-summaries)        /severe-reports/#finding-<id>, the plain summary
 *                                        and the state's own words, uncut in meaning
 *   reporting (featured-nominations)     the article, its picture, the featured note
 *   money (federal-funding / add)        the facility page, the entry as it reads there
 *   numbers                              one card: the week's counts by queue
 *
 * Bulk work (drive-docs, doc-types, fornits, woodbury, merges) never makes a
 * card. At most kop_vsoc_per_angle (8) cards per angle, newest first. A card
 * key is '<angle>:<id>', so a week's cards never repeat an item.
 * Tested by scripts/test-volunteer-projects.php ("Social media roundup").
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The angles in the order the designer sees them: what matters most first. */
function kop_vsoc_angles() {
    return array(
        'closure'   => array('label' => 'Closure', 'source' => 'closure', 'actions' => array('apply')),
        'lawsuit'   => array('label' => 'New lawsuit', 'source' => 'lawsuit', 'actions' => array('approve')),
        'bill'      => array('label' => 'Bill', 'source' => 'legislation', 'actions' => array('approve')),
        'finding'   => array('label' => 'State finding', 'source' => 'highlight-summaries', 'actions' => array('approved')),
        'reporting' => array('label' => 'Essential reporting', 'source' => 'featured-nominations', 'actions' => array('feature')),
        'money'     => array('label' => 'Public money', 'source' => 'federal-funding', 'actions' => array('add')),
    );
}

/** What the week-in-numbers card counts: [source, action, words for one, words for many]. */
function kop_vsoc_number_lines() {
    return array(
        array('inspection-highlights', 'approved', 'serious state inspection finding added', 'serious state inspection findings added'),
        array('highlight-summaries', 'approved', 'finding explained in plain words', 'findings explained in plain words'),
        array('news', 'approve', 'news story added', 'news stories added'),
        array('closure', 'apply', 'closure recorded', 'closures recorded'),
        array('lawsuit', 'approve', 'lawsuit added', 'lawsuits added'),
        array('legislation', 'approve', 'bill added', 'bills added'),
        array('federal-funding', 'add', 'program\'s public money recorded', 'programs\' public money recorded'),
        array('featured-nominations', 'feature', 'story added to Essential reporting', 'stories added to Essential reporting'),
        array('wiki-updates', 'posted', 'wiki entry updated', 'wiki entries updated'),
    );
}

/** The approved log rows of one queue between two times, newest first, one per item. */
function kop_vsoc_log_rows($source, array $actions, $from, $to) {
    global $wpdb;
    $ph = implode(',', array_fill(0, count($actions), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare('SELECT item_key, MAX(created_at) AS at FROM ' . kop_rinbox_log_table()
        . " WHERE source = %s AND action IN ($ph) AND undone_at IS NULL AND created_at >= %s AND created_at < %s GROUP BY item_key ORDER BY at DESC",
        array_merge(array((string) $source), $actions, array($from, $to))), ARRAY_A);
    return (array) $rows;
}

/** Cut to about $max characters at a word, with an ellipsis. */
function kop_vsoc_cut($text, $max = 400) {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, ' ,;:') . '…';
}

/** A facilities_v2 record's name, place and public page: [name, place, url] or null. */
function kop_vsoc_facility($fid) {
    global $wpdb;
    if (!(int) $fid) return null;
    $r = $wpdb->get_row($wpdb->prepare('SELECT id, name, city, state FROM facilities_v2 WHERE id = %d', (int) $fid), ARRAY_A);
    if (!$r) return null;
    $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $fid) : '';
    $place = implode(', ', array_filter(array(trim((string) $r['city']), trim((string) $r['state']))));
    return array((string) $r['name'], $place, preg_match('#^https?://#', $url) ? $url : '');
}

function kop_vsoc_date($d) {
    $t = $d ? strtotime((string) $d) : false;
    return $t ? gmdate('F j, Y', $t) : '';
}

/** One card per item worth a post, between two UTC times ('Y-m-d H:i:s'), then the week in numbers. */
function kop_vsoc_cards($from, $to) {
    $per = max(1, (int) apply_filters('kop_vsoc_per_angle', 8));
    $cards = array();
    foreach (kop_vsoc_angles() as $angle => $a) {
        $n = 0;
        foreach (kop_vsoc_log_rows($a['source'], $a['actions'], $from, $to) as $row) {
            try {
                $c = call_user_func('kop_vsoc_card_' . $angle, (string) $row['item_key']);
            } catch (Throwable $e) {
                $c = null;   // the records database unreachable, or the item gone
            }
            if (!$c) continue;
            $c += array('url' => '', 'url_label' => '', 'summary' => '', 'quote' => '', 'image' => '', 'when' => '');
            $c['key'] = $angle . ':' . $row['item_key'];
            $c['angle'] = $angle;
            $c['angle_label'] = $a['label'];
            $cards[] = $c;
            if (++$n >= $per) break;
        }
    }
    $numbers = kop_vsoc_card_numbers($from, $to);
    if ($numbers && $cards) $cards[] = $numbers;
    return $cards;
}

function kop_vsoc_card_closure($id) {
    $pdo = kop_rinbox_pdo();
    $st = $pdo->prepare("SELECT * FROM facility_closure_reports WHERE id = ? AND status = 'applied'");
    $st->execute(array((int) $id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !(int) $r['facility_id']) return null;   // filed on another kind of record
    $f = kop_vsoc_facility((int) $r['facility_id']);
    if (!$f) return null;
    $when = trim((string) $r['closure_date']);
    return array(
        'fact' => $f[0] . ($f[1] !== '' ? ' (' . $f[1] . ')' : '') . ' has closed' . ($when !== '' ? ', ' . $when : '') . '.',
        'url' => $f[2], 'url_label' => 'Our page on ' . $f[0],
        'quote' => (int) $r['quote_found'] ? kop_vsoc_cut((string) $r['quote'], 400) : '',
    );
}

function kop_vsoc_card_lawsuit($id) {
    $pdo = kop_rinbox_pdo();
    $st = $pdo->prepare("SELECT * FROM lawsuits WHERE id = ? AND publication_status = 'published'");
    $st->execute(array((int) $id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $where = trim((string) ($r['court'] ?: $r['jurisdiction']));
    $filed = kop_vsoc_date($r['filing_date']);
    return array(
        'fact' => trim((string) $r['case_name']) . ($where !== '' ? ', ' . $where : '') . ($filed !== '' ? ', filed ' . $filed : '') . '.',
        'url' => home_url('/lawsuits/#lawsuit-' . (int) $r['id']), 'url_label' => 'The case on our lawsuits page',
        'summary' => kop_vsoc_cut((string) $r['summary'], 600), 'when' => $filed,
    );
}

function kop_vsoc_card_bill($id) {
    $pdo = kop_rinbox_pdo();
    $st = $pdo->prepare("SELECT * FROM legislation WHERE id = ? AND publication_status = 'published'");
    $st->execute(array((int) $id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $name = trim(trim((string) $r['jurisdiction']) . ' ' . trim((string) $r['bill_number']));
    return array(
        'fact' => $name . ': ' . trim((string) $r['bill_title']) . (trim((string) $r['status']) !== '' ? ' (' . trim((string) $r['status']) . ')' : '') . '.',
        'url' => home_url('/legislation/#bill-' . (int) $r['id']), 'url_label' => 'The bill on our legislation page',
        'summary' => kop_vsoc_cut((string) $r['summary'], 600),
    );
}

/** A finding with an approved plain summary: the summary, and the state's words as they stand (a child's harm is never reworded). */
function kop_vsoc_card_finding($id) {
    $pdo = kop_rinbox_pdo();
    $st = $pdo->prepare("SELECT h.id, h.excerpt, h.finding_date, h.status, f.facility_name, f.state FROM inspection_highlights h
        JOIN inspection_facilities f ON f.id = h.facility_id WHERE h.id = ?");
    $st->execute(array((int) $id));
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (!$h || $h['status'] !== 'approved') return null;
    $summary = function_exists('kop_hs_public_summaries') ? (kop_hs_public_summaries(array($h))[(int) $h['id']] ?? '') : '';
    if ($summary === '') return null;   // the summary no longer matches the finding, or it was taken down
    $name = trim((string) $h['facility_name']);
    $when = kop_vsoc_date($h['finding_date']);
    return array(
        'fact' => trim((string) $h['state']) . ' inspectors\' finding at ' . $name . ($when !== '' ? ', ' . $when : '') . '.',
        'url' => function_exists('kop_ih_severe_page_url') ? kop_ih_severe_page_url((int) $h['id']) : home_url('/severe-reports/#finding-' . (int) $h['id']),
        'url_label' => 'The finding on our serious findings page',
        'summary' => $summary, 'quote' => kop_vsoc_cut((string) $h['excerpt'], 700), 'when' => $when,
    );
}

function kop_vsoc_card_reporting($news_id) {
    $pdo = kop_rinbox_pdo();
    $st = $pdo->prepare("SELECT n.id, n.article_title, n.publication_name, n.publication_date, n.article_url, n.summary, f.note FROM news_featured f
        JOIN news_submissions n ON n.id = f.news_id WHERE f.news_id = ? AND n.status IN ('approved', 'published')");
    $st->execute(array((int) $news_id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $img = function_exists('kop_news_image') ? kop_news_image((int) $r['id'], (string) $r['article_url']) : null;
    $when = kop_vsoc_date($r['publication_date']);
    return array(
        'fact' => '"' . trim((string) $r['article_title']) . '"' . (trim((string) $r['publication_name']) !== '' ? ', ' . trim((string) $r['publication_name']) : '') . ($when !== '' ? ', ' . $when : '') . '.',
        'url' => function_exists('kop_feat_url') ? kop_feat_url() : home_url('/essential-reporting/'), 'url_label' => 'Our Essential reporting page',
        'summary' => kop_vsoc_cut(trim((string) $r['note']) !== '' ? (string) $r['note'] : (string) $r['summary'], 600),
        'image' => is_array($img) && ($img['kind'] ?? '') === 'photo' ? (string) $img['src'] : '', 'when' => $when,
    );
}

function kop_vsoc_card_money($key) {
    if (!function_exists('kop_ffund_proposals')) return null;
    $p = kop_ffund_proposals()[$key] ?? null;
    $d = function_exists('kop_ffund_decisions') ? (kop_ffund_decisions()[$key] ?? array()) : array();
    if (!$p || ($d['decision'] ?? '') !== 'applied' || !empty($d['filed']) || !(int) ($d['facility_id'] ?? 0)) return null;
    $f = kop_vsoc_facility((int) $d['facility_id']);
    if (!$f) return null;
    $entries = function_exists('kop_ffund_entries') ? kop_ffund_entries($key, $p) : (array) $p['entries'];
    $lines = array();
    foreach ($entries as $e) if (trim((string) ($e['detail'] ?? '')) !== '') $lines[] = trim((string) $e['detail']);
    if (!$lines) return null;
    return array(
        'fact' => $f[0] . ($f[1] !== '' ? ' (' . $f[1] . ')' : '') . ' is paid with public money.',
        'url' => $f[2], 'url_label' => 'Our page on ' . $f[0], 'summary' => kop_vsoc_cut(implode(' ', $lines), 600),
    );
}

/** The week in numbers: one card of counts, or null when nothing counted happened. */
function kop_vsoc_card_numbers($from, $to) {
    $lines = array();
    foreach (kop_vsoc_number_lines() as $l) {
        $n = count(kop_vsoc_log_rows($l[0], array($l[1]), $from, $to));
        if ($n) $lines[] = number_format($n) . ' ' . ($n === 1 ? $l[2] : $l[3]);
    }
    if (!$lines) return null;
    return array(
        'key' => 'numbers:' . substr($from, 0, 10), 'angle' => 'numbers', 'angle_label' => 'The week in numbers',
        'fact' => 'Our week: ' . $lines[0] . '.', 'url' => home_url('/'), 'url_label' => 'kidsoverprofits.org',
        'summary' => implode('; ', $lines) . '.', 'quote' => '', 'image' => '', 'when' => '',
    );
}
