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
 *   page (drafts / publish)              a page of ours published that week
 *   news (news / approve)                a facility page the story is linked to (else the
 *                                        story), its summary and picture; one card per story
 *
 * Only news makes those cards: the item's own date (the closure, else its
 * article; the filing; the bill's last action, else its introduction; the
 * finding; the article) must fall within kop_vsoc_max_age_days (60) of the
 * roundup's end, and an item with no date makes none (owner, 2026-10-21:
 * approving a 1986 closure this week is not news). So public money, which has
 * only years, makes none. A story covered by several outlets is one card
 * (kop_vsoc_one_per_story(): the same story group, the same program, or the
 * same headline words), naming the other outlets (owner, 2026-10-23).
 *
 * Then the site updates (kop_vsoc_site_cards()), each its own card naming
 * what changed, whatever its age, since that the site grew is this week's
 * news: programs added to the directory, records brought up to date (closures,
 * lawsuit and bill updates), documents added to the library, serious state
 * findings added, and one card of everything else counted. A week with
 * anything done is never empty (owner, 2026-10-23).
 *
 * Bulk work (drive-docs, doc-types, fornits, woodbury, merges) never makes a
 * card of its own. At most kop_vsoc_per_angle (8) cards per angle, newest
 * first. A card key is '<angle>:<id>', so a week's cards never repeat an item.
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
        'page'      => array('label' => 'New on our site', 'source' => 'drafts', 'actions' => array('publish')),
        'news'      => array('label' => 'In the news', 'source' => 'news', 'actions' => array('approve')),
    );
}

/**
 * What the "Also this week" card counts: [source, action, words for one, words
 * for many]. Findings, closures and status updates have cards of their own.
 */
function kop_vsoc_number_lines() {
    return array(
        array('news', 'approve', 'news story added', 'news stories added'),
        array('highlight-summaries', 'approved', 'finding explained in plain words', 'findings explained in plain words'),
        array('lawsuit', 'approve', 'lawsuit added', 'lawsuits added'),
        array('legislation', 'approve', 'bill added', 'bills added'),
        array('federal-funding', 'add', 'program\'s public money recorded', 'programs\' public money recorded'),
        array('featured-nominations', 'feature', 'story added to Essential reporting', 'stories added to Essential reporting'),
        array('wiki-updates', 'posted', 'wiki entry updated', 'wiki entries updated'),
        array('drafts', 'publish', 'new page published', 'new pages published'),
        array('websites', 'publish', 'website about a program added', 'websites about programs added'),
        array('program-practices', 'add', 'section of a program\'s rules and practices added', 'sections of programs\' rules and practices added'),
        array('woodbury-facts', 'apply', 'fact from Woodbury Reports added to a record', 'facts from Woodbury Reports added to records'),
        array('facilities-from-news', 'link', 'news story linked to the program it is about', 'news stories linked to the programs they are about'),
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

/**
 * Whether a date ('Y', 'Y-m' or 'Y-m-d'; a part date counts as its last day)
 * is within kop_vsoc_max_age_days of $to. No date, or one that does not read, is not.
 */
function kop_vsoc_recent($d, $to) {
    if (!preg_match('/^(\d{4})(?:-(\d{1,2}))?(?:-(\d{1,2}))?/', trim((string) $d), $m)) return false;
    $y = (int) $m[1];
    $mo = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 12;
    $day = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) gmdate('t', gmmktime(0, 0, 0, $mo, 1, $y));
    $at = gmmktime(23, 59, 59, $mo, $day, $y);
    $days = max(1, (int) apply_filters('kop_vsoc_max_age_days', 60));
    return $at >= strtotime($to . ' UTC') - $days * DAY_IN_SECONDS;
}

function kop_vsoc_date($d) {
    $t = $d ? strtotime((string) $d) : false;
    return $t ? gmdate('F j, Y', $t) : '';
}

/** One card per item worth a post, between two UTC times ('Y-m-d H:i:s'), then the site updates. */
function kop_vsoc_cards($from, $to) {
    $per = max(1, (int) apply_filters('kop_vsoc_per_angle', 8));
    $cards = array();
    foreach (kop_vsoc_angles() as $angle => $a) {
        $found = array();
        foreach (kop_vsoc_log_rows($a['source'], $a['actions'], $from, $to) as $row) {
            try {
                $c = call_user_func('kop_vsoc_card_' . $angle, (string) $row['item_key']);
            } catch (Throwable $e) {
                $c = null;   // the records database unreachable, or the item gone
            }
            if (!$c || !kop_vsoc_recent($c['dated'] ?? '', $to)) continue;   // old news approved this week is still old
            if ($angle === 'news' && isset($cards['reporting:' . $row['item_key']])) continue;   // already a card as essential reporting
            unset($c['dated']);
            $c += array('url' => '', 'url_label' => '', 'summary' => '', 'quote' => '', 'image' => '', 'when' => '');
            $c['key'] = $angle . ':' . $row['item_key'];
            $c['angle'] = $angle;
            $c['angle_label'] = $a['label'];
            $found[] = $c;
            if ($angle !== 'news' && count($found) >= $per) break;   // news is cut after its stories are rolled up
        }
        if ($angle === 'news') $found = kop_vsoc_one_per_story($found);
        foreach (array_slice($found, 0, $per) as $c) $cards[$c['key']] = $c;
    }
    foreach (kop_vsoc_site_cards($from, $to, $cards) as $c) $cards[$c['key']] = $c;
    return array_values($cards);
}

/** A headline's telling words: lower case, four letters or more, without the words every story in the field shares. */
function kop_vsoc_story_words($title) {
    static $common = null;
    if ($common === null) {
        $common = array_flip(explode(' ', 'about after again also amid been before being center centre child children childrens could detention during facility '
            . 'former from group have home homes into juvenile lawsuit lawsuits more news officials over plans said says school schools staff state '
            . 'states teen teens than that their them they this treatment under will with were what when where which while youth residential abuse '
            . 'alleged allege alleges report reports county city years year week'));
    }
    $t = mb_strtolower(str_replace(array("\u{2019}", "'"), '', (string) $title));
    $out = array();
    foreach (preg_split('/[^\p{L}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (mb_strlen($w) >= 4 && !isset($common[$w])) $out[$w] = true;
    }
    return array_keys($out);
}

/** Whether two headlines tell the same story: three telling words shared, or two that are half of either. */
function kop_vsoc_same_story($a, $b) {
    $wa = kop_vsoc_story_words($a);
    $wb = kop_vsoc_story_words($b);
    $shared = count(array_intersect($wa, $wb));
    return $shared >= 3 || ($shared >= 2 && $shared * 2 >= min(count($wa), count($wb)));
}

/**
 * One card per story: news cards in the same story group, linked to the same
 * program or with the same headline words roll up into one. The card kept is
 * the first linked to a facility page (else the newest), and names the other
 * outlets; stories with a page of ours and wider coverage come first.
 */
function kop_vsoc_one_per_story(array $found) {
    $stories = array();
    foreach ($found as $c) {
        $hit = null;
        foreach ($stories as $i => $s) {
            foreach ($s as $o) {
                if ($o['_group'] === $c['_group'] || ($c['_fac'] && $o['_fac'] === $c['_fac']) || kop_vsoc_same_story($o['_title'], $c['_title'])) { $hit = $i; break 2; }
            }
        }
        if ($hit === null) $stories[] = array($c); else $stories[$hit][] = $c;
    }
    $out = array();
    foreach ($stories as $n => $s) {
        $lead = $s[0];   // the one whose headline names its program, else the first with a page of ours, else the newest
        foreach ($s as $o) if ($o['_named'] > $lead['_named'] || ($o['_named'] === $lead['_named'] && $o['_fac'] && !$lead['_fac'])) $lead = $o;
        $others = array();
        foreach ($s as $o) if ($o['key'] !== $lead['key'] && $o['_outlet'] !== '' && $o['_outlet'] !== $lead['_outlet']) $others[$o['_outlet']] = true;
        if ($others) $lead['summary'] = trim($lead['summary'] . ' Also covered by ' . implode(', ', array_keys($others)) . '.');
        $out[] = array('rank' => array($lead['_fac'] ? 0 : 1, -count($s), $n), 'card' => $lead);
    }
    usort($out, function ($x, $y) { return $x['rank'] <=> $y['rank']; });
    return array_map(function ($x) { return array_diff_key($x['card'], array('_group' => 1, '_fac' => 1, '_title' => 1, '_outlet' => 1, '_named' => 1)); }, $out);
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
    $article = '';
    if ((int) $r['news_id']) {
        $st = $pdo->prepare('SELECT publication_date FROM news_submissions WHERE id = ?');
        $st->execute(array((int) $r['news_id']));
        $article = (string) $st->fetchColumn();
    }
    return array(
        'dated' => $when !== '' ? $when : $article,
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
        'dated' => (string) $r['filing_date'],
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
        'dated' => (string) ($r['last_action_date'] ?: $r['introduced_date']),
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
        'dated' => (string) $h['finding_date'],
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
        'dated' => (string) $r['publication_date'],
        'fact' => '"' . trim((string) $r['article_title']) . '"' . (trim((string) $r['publication_name']) !== '' ? ', ' . trim((string) $r['publication_name']) : '') . ($when !== '' ? ', ' . $when : '') . '.',
        'url' => function_exists('kop_feat_url') ? kop_feat_url() : home_url('/essential-reporting/'), 'url_label' => 'Our Essential reporting page',
        'summary' => kop_vsoc_cut(trim((string) $r['note']) !== '' ? (string) $r['note'] : (string) $r['summary'], 600),
        'image' => is_array($img) && ($img['kind'] ?? '') === 'photo' ? (string) $img['src'] : '', 'when' => $when,
    );
}

/** A page of ours published from the drafts queue, still published. */
function kop_vsoc_card_page($id) {
    $p = get_post((int) $id);
    if (!$p || $p->post_status !== 'publish' || !in_array($p->post_type, array('page', 'post'), true)) return null;
    $url = (string) get_permalink($p);
    $title = trim((string) $p->post_title);
    if ($title === '' || !preg_match('#^https?://#', $url)) return null;
    return array(
        'dated' => (string) $p->post_date_gmt,
        'fact' => 'New on our site: ' . $title . '.',
        'url' => $url, 'url_label' => $title,
        'summary' => kop_vsoc_cut(trim((string) $p->post_excerpt) !== '' ? (string) $p->post_excerpt : wp_strip_all_tags((string) $p->post_content), 400),
    );
}

/** An approved news story: the facility page it is linked to (else the story itself), its summary and picture. */
function kop_vsoc_card_news($news_id) {
    $pdo = kop_rinbox_pdo();
    $st = $pdo->prepare("SELECT id, article_title, publication_name, publication_date, article_url, summary, story_group_id FROM news_submissions WHERE id = ? AND status IN ('approved', 'published')");
    $st->execute(array((int) $news_id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !preg_match('#^https?://#', (string) $r['article_url'])) return null;
    $outlet = trim((string) $r['publication_name']);
    $url = (string) $r['article_url'];
    $label = 'The story' . ($outlet !== '' ? ' at ' . $outlet : '');
    $fac = 0;
    $fac_name = '';
    $st = $pdo->prepare("SELECT facility_id FROM news_facility_links WHERE news_id = ? ORDER BY link_type = 'mentioned' DESC, facility_id");
    $st->execute(array((int) $r['id']));
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $f = kop_vsoc_facility((int) $fid);
        if ($f && $f[2] !== '') { $url = $f[2]; $label = 'Our page on ' . $f[0]; $fac = (int) $fid; $fac_name = $f[0]; break; }
    }
    $img = function_exists('kop_news_image') ? kop_news_image((int) $r['id'], (string) $r['article_url']) : null;
    $when = kop_vsoc_date($r['publication_date']);
    return array(
        'dated' => (string) $r['publication_date'],
        'fact' => '"' . trim((string) $r['article_title']) . '"' . ($outlet !== '' ? ', ' . $outlet : '') . ($when !== '' ? ', ' . $when : '') . '.',
        'url' => $url, 'url_label' => $label, 'summary' => kop_vsoc_cut((string) $r['summary'], 600),
        'image' => is_array($img) && ($img['kind'] ?? '') === 'photo' ? (string) $img['src'] : '', 'when' => $when,
        // For kop_vsoc_one_per_story(); taken off before the card is kept.
        '_group' => (int) ($r['story_group_id'] ?: $r['id']), '_fac' => $fac, '_title' => (string) $r['article_title'], '_outlet' => $outlet,
        '_named' => $fac && array_intersect(kop_vsoc_story_words($fac_name), kop_vsoc_story_words($r['article_title'])) ? 1 : 0,
    );
}

/** "A, B and C", with "and N more" past $max. */
function kop_vsoc_list(array $names, $max = 8) {
    $names = array_values(array_unique(array_filter(array_map('trim', $names), 'strlen')));
    $more = count($names) - $max;
    if ($more > 0) { $names = array_slice($names, 0, $max); $names[] = number_format($more) . ' more'; }
    if (count($names) < 2) return (string) ($names[0] ?? '');
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

/** The site updates, each a card naming what changed that week; $cards = the news cards made already. */
function kop_vsoc_site_cards($from, $to, array $cards) {
    $out = array();
    foreach (array('directory', 'records', 'documents', 'findings', 'numbers') as $kind) {
        try {
            $c = call_user_func('kop_vsoc_site_' . $kind, $from, $to, $cards);
        } catch (Throwable $e) {
            $c = null;   // the records database unreachable
        }
        if (!$c) continue;
        $out[] = $c + array('key' => $kind . ':' . substr($from, 0, 10), 'angle' => $kind, 'angle_label' => 'This week on the site',
            'url' => home_url('/'), 'url_label' => 'kidsoverprofits.org', 'summary' => '', 'quote' => '', 'image' => '', 'when' => '');
    }
    return $out;
}

/** Programs added to the directory that week, those with a type first (a lower-case placeholder name is not a program to show). */
function kop_vsoc_site_directory($from, $to) {
    global $wpdb;
    $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id, name, city, state FROM facilities_v2 WHERE created_at >= %s AND created_at < %s
        ORDER BY COALESCE(facility_type, '') = '', created_at DESC, id DESC", $from, $to), ARRAY_A);
    $names = array();
    foreach ($rows as $r) {
        $name = trim((string) $r['name']);
        if ($name === '' || mb_strtolower($name) === $name) continue;
        $names[] = $name . (trim((string) $r['state']) !== '' ? ' (' . trim((string) $r['state']) . ')' : '');
    }
    if (!$names) return null;
    $n = count($names);
    return array(
        'fact' => number_format($n) . ($n === 1 ? ' program added' : ' programs added') . ' to our directory.',
        'url' => function_exists('kop_location_index_url') ? kop_location_index_url() : home_url('/tti-program-index/?view=location'), 'url_label' => 'Our directory, by location',
        'summary' => 'New: ' . kop_vsoc_list($names, 12) . '.',
    );
}

/** Records brought up to date: programs now recorded as closed, lawsuits and bills whose status changed. */
function kop_vsoc_site_records($from, $to, array $cards) {
    $pdo = kop_rinbox_pdo();
    $closed = array();
    $url = '';
    foreach (kop_vsoc_log_rows('closure', array('apply'), $from, $to) as $row) {
        if (isset($cards['closure:' . $row['item_key']])) continue;   // a card of its own already
        $st = $pdo->prepare("SELECT facility_id FROM facility_closure_reports WHERE id = ? AND status = 'applied'");
        $st->execute(array((int) $row['item_key']));
        $f = kop_vsoc_facility((int) $st->fetchColumn());
        if (!$f) continue;
        $closed[] = $f[0];
        if ($url === '' && $f[2] !== '') $url = $f[2];
    }
    $changed = array();
    foreach (kop_vsoc_log_rows('status_checks', array('apply'), $from, $to) as $row) {
        $st = $pdo->prepare("SELECT kind, record_id, changes FROM record_status_proposals WHERE id = ? AND status = 'applied'");
        $st->execute(array((int) $row['item_key']));
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;
        $bill = $p['kind'] === 'bill';
        $st = $pdo->prepare($bill ? "SELECT jurisdiction, bill_number FROM legislation WHERE id = ? AND publication_status = 'published'"
            : "SELECT case_name FROM lawsuits WHERE id = ? AND publication_status = 'published'");
        $st->execute(array((int) $p['record_id']));
        // A case name pasted with its docket site's title ("... | Justia") keeps only the name.
        $name = trim(preg_replace('/\s+\|.*$/s', '', implode(' ', array_map('trim', (array) $st->fetch(PDO::FETCH_NUM)))));
        if ($name === '') continue;
        $ch = json_decode((string) $p['changes'], true);
        $now = is_array($ch['status'] ?? null) ? trim((string) ($ch['status'][1] ?? '')) : '';
        $changed[] = $name . ($now !== '' ? ' (now ' . $now . ')' : ' (outcome updated)');
    }
    if (!$closed && !$changed) return null;
    $facts = array();
    if ($closed) $facts[] = number_format(count($closed)) . (count($closed) === 1 ? ' program' : ' programs') . ' now recorded as closed';
    if ($changed) $facts[] = number_format(count($changed)) . (count($changed) === 1 ? ' lawsuit or bill' : ' lawsuits and bills') . ' brought up to date';
    $summary = array();
    if ($closed) $summary[] = 'Now recorded as closed: ' . kop_vsoc_list($closed) . '.';
    if ($changed) $summary[] = 'Updated from the court docket or the legislature: ' . kop_vsoc_list($changed) . '.';
    $c = array('fact' => 'Records brought up to date: ' . implode('; ', $facts) . '.', 'summary' => implode(' ', $summary));
    if ($changed) $c += array('url' => home_url('/lawsuits/'), 'url_label' => 'Our lawsuits page');
    elseif ($url !== '') $c += array('url' => $url, 'url_label' => 'Our page on ' . $closed[0]);
    return $c;
}

/** Documents (PDFs) added to the media library that week. */
function kop_vsoc_site_documents($from, $to) {
    global $wpdb;
    $t = $wpdb->prefix . 'posts';
    $base = "FROM $t WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf' AND post_date_gmt >= %s AND post_date_gmt < %s";
    $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $base", $from, $to));
    if (!$n) return null;
    $insp = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $base AND post_title LIKE %s", $from, $to, '%insp-%'));
    $wood = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $base AND post_title LIKE %s", $from, $to, 'Woodbury Reports%'));
    $parts = array();
    if ($insp) $parts[] = number_format($insp) . ($insp === 1 ? ' state inspection report' : ' state inspection reports');
    if ($wood) $parts[] = number_format($wood) . ($wood === 1 ? ' Woodbury Reports page' : ' Woodbury Reports pages');
    $rest = $n - $insp - $wood;
    if ($parts && $rest > 0) $parts[] = number_format($rest) . ($rest === 1 ? ' other' : ' others');
    return array(
        'fact' => number_format($n) . ($n === 1 ? ' document' : ' documents') . ' added to our library.',
        'url' => function_exists('kop_dt_url') ? kop_dt_url() : home_url('/documents/'), 'url_label' => 'Our documents',
        'summary' => $parts ? 'Among them: ' . kop_vsoc_list($parts) . '.' : '',
    );
}

/** Serious state inspection findings added that week, with the programs that have the most. */
function kop_vsoc_site_findings($from, $to) {
    $rows = kop_vsoc_log_rows('inspection-highlights', array('approved'), $from, $to);
    if (!$rows) return null;
    $ids = implode(',', array_map('intval', array_column($rows, 'item_key')));
    $pdo = kop_rinbox_pdo();
    $by = $pdo->query("SELECT f.facility_name, f.state, COUNT(*) AS n, MIN(h.finding_date) AS first, MAX(h.finding_date) AS last FROM inspection_highlights h
        JOIN inspection_facilities f ON f.id = h.facility_id WHERE h.id IN ($ids) AND h.status = 'approved' GROUP BY f.id, f.facility_name, f.state ORDER BY n DESC, f.facility_name")->fetchAll(PDO::FETCH_ASSOC);
    if (!$by) return null;
    $n = (int) array_sum(array_column($by, 'n'));
    $names = array();
    foreach (array_slice($by, 0, 5) as $r) $names[] = trim((string) $r['facility_name']) . ' (' . trim((string) $r['state']) . ', ' . (int) $r['n'] . ')';
    $states = count(array_unique(array_column($by, 'state')));
    $years = array_filter(array_merge(array_column($by, 'first'), array_column($by, 'last')));
    $span = $years ? substr(min($years), 0, 4) . (substr(min($years), 0, 4) !== substr(max($years), 0, 4) ? ' to ' . substr(max($years), 0, 4) : '') : '';
    return array(
        'fact' => number_format($n) . ($n === 1 ? ' serious finding' : ' serious findings') . ' from state inspection reports added, at ' . number_format(count($by))
            . (count($by) === 1 ? ' program' : ' programs') . ' in ' . $states . ($states === 1 ? ' state' : ' states') . '.',
        'url' => function_exists('kop_ih_severe_page_url') ? kop_ih_severe_page_url() : home_url('/severe-reports/'), 'url_label' => 'Our serious findings page',
        'summary' => 'Most at ' . kop_vsoc_list($names, 5) . '.' . ($span !== '' ? ' The reports date from ' . $span . '.' : ''),
    );
}

/** Everything else counted that week, in one card. */
function kop_vsoc_site_numbers($from, $to) {
    $lines = array();
    foreach (kop_vsoc_number_lines() as $l) {
        $n = count(kop_vsoc_log_rows($l[0], array($l[1]), $from, $to));
        if ($n) $lines[] = number_format($n) . ' ' . ($n === 1 ? $l[2] : $l[3]);
    }
    if (!$lines) return null;
    return array('fact' => 'Also this week: ' . kop_vsoc_list(array_slice($lines, 0, 2)) . '.', 'summary' => implode('; ', $lines) . '.');
}
