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
 * Then the site's own work as post ideas (kop_vsoc_site_cards(),
 * kop_vsoc_site_kinds()), each about one program or case with something to
 * say, never a tally (owner, 2026-10-25): a program's rules in its own
 * handbook's words, a program the state cited again and again, a ruling or a
 * bill's fate dated within two months, a new program that already has news or
 * lawsuits; then one recap card of the week's counts, so a week with anything
 * done is never empty (owner, 2026-10-23).
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
 * What the recap card counts besides programs, findings, closures, status
 * updates and documents: [source, action, words for one, words for many].
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


/**
 * The site's own work that week as post ideas (kop_vsoc_site_kinds()), each
 * about one program or case and saying something worth reading (owner,
 * 2026-10-25: "38 programs added to our directory" is not a post), then one
 * recap card of the week's counts. $cards = the news cards made already.
 */
function kop_vsoc_site_cards($from, $to, array $cards) {
    $per = max(1, (int) apply_filters('kop_vsoc_site_per_kind', 3));
    $out = array();
    foreach (kop_vsoc_site_kinds() as $kind => $label) {
        try {
            $made = (array) call_user_func('kop_vsoc_site_' . $kind, $from, $to, $cards);
        } catch (Throwable $e) {
            $made = array();   // the records database unreachable
        }
        foreach (array_slice($made, 0, $per) as $c) {
            $c += array('url' => home_url('/'), 'url_label' => 'kidsoverprofits.org', 'summary' => '', 'quote' => '', 'image' => '', 'when' => '');
            $c['key'] = $kind . ':' . $c['key'];
            $c['angle'] = $kind;
            $c['angle_label'] = $label;
            $out[] = $c;
        }
    }
    return $out;
}

/**
 * The site cards in order:
 *   rules       a program's rules in its own handbook's words (program-practices / add)
 *   pattern     a program cited again and again in the findings added that week
 *   outcome     a ruling, settlement or verdict dated within two months, or a bill
 *               signed, vetoed or dead, from the status checks applied that week
 *   newprogram  a program new to the directory that already has news or lawsuits
 *   recap       the week's counts, for a weekly recap post
 */
function kop_vsoc_site_kinds() {
    return array(
        'rules'      => 'In their own words',
        'pattern'    => 'Inspection record',
        'outcome'    => 'Case or bill update',
        'newprogram' => 'New in our directory',
        'recap'      => 'Weekly recap',
    );
}

/** Topics in the order a line of them makes the strongest post. */
function kop_vsoc_rule_order() {
    return array('restraint', 'searches', 'rules', 'consequences', 'communication', 'levels', 'therapy', 'family', 'daily_life', 'education', 'admission');
}

/**
 * How hard a rule line hits as a post: the control it names (strip searches,
 * isolation, silence, letters opened, being sent away, unpaid work, consent
 * signed away), one point each; a line too long for a first line loses one,
 * and so does one about the parents (what they were told, what they paid).
 */
function kop_vsoc_rule_bite($line, $topic = '') {
    $n = preg_match_all('/strip|cavit|restrain|isolat|\balone\b|silen|not allowed|could not|no (?:contact|visits?|calls?|talking)|forbid|barred|opened|read by staff|monitor|'
        . 'march|attention|bathroom|out loud|sent (?:\w+ ){0,6}to|expense|demot|cut off|terminat|punish|seminar|confront|hot ?seat|unpaid|labou?r|consent|sole discretion|'
        . 'at any time|whenever/i', (string) $line);
    return (int) $n - (mb_strlen((string) $line) > 240 ? 1 : 0) - (in_array($topic, array('family', 'admission'), true) ? 1 : 0);
}

/** Programs whose rules and practices were added that week, the most lines first: the strongest line, quoted, and a few more. */
function kop_vsoc_site_rules($from, $to) {
    if (!function_exists('kop_pp_rows')) return array();
    $topics = array();
    foreach (kop_vsoc_log_rows('program-practices', array('add'), $from, $to) as $row) {
        list($fid, $topic) = array_pad(explode(':', (string) $row['item_key'], 2), 2, '');
        if ((int) $fid) $topics[(int) $fid][$topic] = true;
    }
    $rank = array_flip(kop_vsoc_rule_order());
    $found = array();
    foreach ($topics as $fid => $t) {
        $f = kop_vsoc_facility($fid);
        if (!$f || $f[2] === '') continue;
        $lines = array();
        foreach (kop_pp_rows(array('facility_id' => $fid, 'status' => 'added', 'kind' => 'doc')) as $r) {
            if (isset($t[$r['topic']]) && trim((string) $r['value']) !== '') $lines[] = $r;
        }
        if (!$lines) continue;
        usort($lines, function ($a, $b) use ($rank) {
            return kop_vsoc_rule_bite($b['value'], $b['topic']) <=> kop_vsoc_rule_bite($a['value'], $a['topic']) ?: ($rank[$a['topic']] ?? 99) <=> ($rank[$b['topic']] ?? 99);
        });
        $lead = array_shift($lines);
        // A line from each other topic first, then the rest.
        $seen = array($lead['topic'] => true);
        $more = array();
        foreach ($lines as $r) if (!isset($seen[$r['topic']])) { $seen[$r['topic']] = true; $more[] = $r; }
        foreach ($lines as $r) if (!in_array($r, $more, true)) $more[] = $r;
        $more = array_slice($more, 0, 3);
        $doc = trim((string) $lead['doc_title']) . ((int) $lead['page'] > 0 ? ', page ' . (int) $lead['page'] : '');
        $found[] = array('n' => count($lines) + 1, 'card' => array(
            'key' => (string) $fid,
            'fact' => $f[0] . ($f[1] !== '' ? ' (' . $f[1] . ')' : '') . ', from its own papers: ' . kop_vsoc_cut((string) $lead['value'], 300),
            'url' => $f[2], 'url_label' => 'Rules and practices on our page on ' . $f[0],
            'summary' => $more ? 'Also from its papers: ' . implode(' ', array_map(function ($r) { return kop_vsoc_cut((string) $r['value'], 220); }, $more)) : '',
            'quote' => trim((string) $lead['quote']) !== '' ? '"' . kop_vsoc_cut((string) $lead['quote'], 400) . '"' . ($doc !== '' ? ' (' . $doc . ')' : '') : '',
            'when' => (int) $lead['year'] > 0 ? (string) (int) $lead['year'] : '',
        ));
    }
    usort($found, function ($a, $b) { return $b['n'] <=> $a['n']; });
    return array_column($found, 'card');
}

/** A finding category counted: [one, many]. */
function kop_vsoc_finding_words($cat) {
    $words = array(
        'death'            => array('death', 'deaths'),
        'physical_abuse'   => array('staff assault', 'staff assaults'),
        'sexual_abuse'     => array('sexual abuse finding', 'sexual abuse findings'),
        'restraint_injury' => array('restraint injury', 'restraint injuries'),
        'suicide_attempt'  => array('suicide attempt', 'suicide attempts'),
        'self_harm'        => array('self-harm finding', 'self-harm findings'),
        'medical_neglect'  => array('medical neglect finding', 'medical neglect findings'),
        'hospitalization'  => array('hospitalization', 'hospitalizations'),
        'missing'          => array('missing child', 'missing children'),
    );
    return $words[$cat] ?? array('other serious finding', 'other serious findings');
}

/**
 * Programs with two or more of the serious findings added that week, the most
 * first: how many, of what kind, over which years, and the state's own words
 * of the gravest one (a child's harm is never reworded). The same finding
 * under two inspection rows of one program counts once.
 */
function kop_vsoc_site_pattern($from, $to) {
    $rows = kop_vsoc_log_rows('inspection-highlights', array('approved'), $from, $to);
    if (!$rows) return array();
    $ids = implode(',', array_map('intval', array_column($rows, 'item_key')));
    $pdo = kop_rinbox_pdo();
    $hits = $pdo->query("SELECT h.id, h.category, h.score, h.finding_date, h.excerpt, h.text_hash, f.facility_name, f.state FROM inspection_highlights h
        JOIN inspection_facilities f ON f.id = h.facility_id WHERE h.id IN ($ids) AND h.status = 'approved'")->fetchAll(PDO::FETCH_ASSOC);
    $by = array();
    foreach ($hits as $h) {
        $name = trim((string) $h['facility_name']);
        $g = mb_strtolower($name) . '|' . $h['state'];
        $hash = (string) $h['text_hash'] !== '' ? (string) $h['text_hash'] : 'id' . $h['id'];
        if (!isset($by[$g])) $by[$g] = array('name' => $name, 'state' => trim((string) $h['state']), 'f' => array());
        if (!isset($by[$g]['f'][$hash]) || (string) $h['finding_date'] !== '') $by[$g]['f'][$hash] = $h;
    }
    $by = array_filter($by, function ($g) { return count($g['f']) >= 2; });
    uasort($by, function ($a, $b) { return count($b['f']) <=> count($a['f']) ?: strcmp($a['name'], $b['name']); });
    $out = array();
    foreach ($by as $g) {
        $f = array_values($g['f']);
        $kinds = array();
        foreach ($f as $h) $kinds[(string) $h['category']] = ($kinds[(string) $h['category']] ?? 0) + 1;
        arsort($kinds);
        $parts = array();
        foreach ($kinds as $cat => $n) { $w = kop_vsoc_finding_words($cat); $parts[] = $n . ' ' . ($n === 1 ? $w[0] : $w[1]); }
        $dates = array_filter(array_column($f, 'finding_date'));
        $y1 = $dates ? substr(min($dates), 0, 4) : '';
        $y2 = $dates ? substr(max($dates), 0, 4) : '';
        $span = $y1 === '' ? '' : ($y1 === $y2 ? ' in ' . $y1 : ' from ' . $y1 . ' to ' . $y2);
        usort($f, function ($a, $b) { return (int) $b['score'] <=> (int) $a['score'] ?: strcmp((string) $b['finding_date'], (string) $a['finding_date']); });
        $state = $g['state'] !== '' && function_exists('kop_state_canonical_name') ? (string) kop_state_canonical_name($g['state']) : '';
        $page = function_exists('kop_facility_page_url_for_name') ? (string) kop_facility_page_url_for_name($g['name']) : '';
        $sev = function_exists('kop_ih_severe_page_url') ? kop_ih_severe_page_url((int) $f[0]['id']) : home_url('/severe-reports/#finding-' . (int) $f[0]['id']);
        $when = kop_vsoc_date($f[0]['finding_date']);
        $out[] = array(
            'key' => substr(md5($g['name'] . '|' . $g['state']), 0, 12),
            'fact' => ($state !== '' ? $state : $g['state']) . ' inspectors recorded ' . count($f) . ' serious findings at ' . $g['name'] . $span . ': ' . kop_vsoc_list($parts) . '.',
            'url' => preg_match('#^https?://#', $page) ? $page : $sev,
            'url_label' => preg_match('#^https?://#', $page) ? 'Our page on ' . $g['name'] : 'The findings on our serious findings page',
            'summary' => 'The state\'s own words' . ($when !== '' ? ', ' . $when : '') . ', below; every finding is on our serious findings page.',
            'quote' => kop_vsoc_cut((string) $f[0]['excerpt'], 600),
            'when' => trim($y1 . ($y2 !== $y1 ? '-' . $y2 : '')),
        );
    }
    return $out;
}

/**
 * The newest dated sentence ("On October 5, 2026, ...") of $text that is within
 * two months of $to and says how a case went: [sentence, Y-m-d, strength], 2 for
 * a verdict, settlement, judgment, sentence or the Supreme Court, 1 for a
 * dismissal, appeal or ruling; never a scheduling order or a lawyer leaving.
 */
function kop_vsoc_news_sentence($text, $to) {
    $best = array('', '', 0);
    foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z])/', trim((string) $text)) as $s) {
        $strong = preg_match('/\b(settle|verdict|jury|judgment|judgement|certiorari|supreme court|sentenc|guilty|plea|award|damages)|\$\d/i', $s);
        if (!$strong && !preg_match('/\b(dismiss|appeal|ruled|ruling|class certification|injunction)/i', $s)) continue;
        if (preg_match('/\b(withdraw|counsel|extend|extension|scheduling order|protective order|amended complaint|summons|service of process|notice of compliance)/i', $s)) continue;
        if (!preg_match_all('/\b(January|February|March|April|May|June|July|August|September|October|November|December) (\d{1,2}), (\d{4})\b/', $s, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $d) {
            $at = gmdate('Y-m-d', strtotime($d[0] . ' UTC'));
            if ($at <= substr($to, 0, 10) && kop_vsoc_recent($at, $to) && $at > $best[1]) $best = array(trim($s), $at, $strong ? 2 : 1);
        }
    }
    return $best;
}

/**
 * Status checks applied that week that are news: a ruling, settlement or
 * verdict dated within two months that the check added to a case's outcome,
 * or a bill now signed, enacted, vetoed or dead whose last action is that
 * recent. An old case brought up to date is only counted in the recap.
 */
function kop_vsoc_site_outcome($from, $to, array $cards) {
    $pdo = kop_rinbox_pdo();
    $bill_words = array('signed' => 'signed into law', 'enacted' => 'enacted', 'vetoed' => 'vetoed', 'dead' => 'dead');
    $out = array();
    foreach (kop_vsoc_log_rows('status_checks', array('apply'), $from, $to) as $row) {
        $st = $pdo->prepare("SELECT kind, record_id, changes FROM record_status_proposals WHERE id = ? AND status = 'applied'");
        $st->execute(array((int) $row['item_key']));
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;
        $ch = json_decode((string) $p['changes'], true);
        $ch = is_array($ch) ? $ch : array();
        $id = (int) $p['record_id'];
        $key = $p['kind'] . '-' . $id;
        if (isset($out[$key])) continue;   // the newest check of a record that is news
        if ($p['kind'] === 'bill') {
            $now = is_array($ch['status'] ?? null) ? (string) ($ch['status'][1] ?? '') : '';
            if (!isset($bill_words[$now]) || isset($cards['bill:' . $id])) continue;
            $st = $pdo->prepare("SELECT * FROM legislation WHERE id = ? AND publication_status = 'published'");
            $st->execute(array($id));
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || !kop_vsoc_recent((string) ($r['last_action_date'] ?: $r['introduced_date']), $to)) continue;
            $name = trim(trim((string) $r['jurisdiction']) . ' ' . trim((string) $r['bill_number']));
            $out[$key] = array(
                'key' => $key,
                'fact' => $name . ', ' . trim((string) $r['bill_title']) . ', is now ' . $bill_words[$now] . '.',
                'url' => home_url('/legislation/#bill-' . $id), 'url_label' => 'The bill on our legislation page',
                'summary' => kop_vsoc_cut((string) $r['summary'], 600), 'when' => kop_vsoc_date($r['last_action_date']),
                '_rank' => array(2, (string) $r['last_action_date']),
            );
            continue;
        }
        if ($p['kind'] !== 'lawsuit' || isset($cards['lawsuit:' . $id])) continue;
        $old = is_array($ch['outcome'] ?? null) ? trim((string) ($ch['outcome'][0] ?? '')) : '';
        $new = is_array($ch['outcome'] ?? null) ? trim((string) ($ch['outcome'][1] ?? '')) : '';
        if ($new === '') continue;
        $added = $old !== '' && strpos($new, $old) === 0 ? substr($new, strlen($old)) : $new;
        list($s, $at, $strength) = kop_vsoc_news_sentence($added, $to);
        if ($s === '') continue;
        $st = $pdo->prepare("SELECT case_name, summary FROM lawsuits WHERE id = ? AND publication_status = 'published'");
        $st->execute(array($id));
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $name = $r ? trim(preg_replace('/\s+\|.*$/s', '', (string) $r['case_name'])) : '';
        if ($name === '') continue;
        $out[$key] = array(
            'key' => $key,
            'fact' => $name . ': ' . $s,
            'url' => home_url('/lawsuits/#lawsuit-' . $id), 'url_label' => 'The case on our lawsuits page',
            'summary' => kop_vsoc_cut((string) $r['summary'], 600), 'when' => kop_vsoc_date($at),
            '_rank' => array($strength, $at),
        );
    }
    // Verdicts, settlements and bills signed first, then the newest.
    usort($out, function ($a, $b) { return $b['_rank'] <=> $a['_rank']; });
    return array_map(function ($c) { unset($c['_rank']); return $c; }, $out);
}

/** Programs new to the directory that week that already have news stories or lawsuits on record, the most first. */
function kop_vsoc_site_newprogram($from, $to) {
    global $wpdb;
    $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id FROM facilities_v2 WHERE created_at >= %s AND created_at < %s ORDER BY created_at DESC, id DESC', $from, $to), ARRAY_A);
    if (!$rows) return array();
    $pdo = kop_rinbox_pdo();
    $news = $pdo->prepare("SELECT n.article_title, n.publication_name, n.publication_date FROM news_facility_links l JOIN news_submissions n ON n.id = l.news_id
        WHERE l.facility_id = ? AND n.status IN ('approved', 'published') ORDER BY n.publication_date DESC");
    $suits = $pdo->prepare("SELECT s.case_name FROM lawsuit_facility_links l JOIN lawsuits s ON s.id = l.lawsuit_id WHERE l.facility_id = ? AND s.publication_status = 'published'
        ORDER BY s.filing_date DESC");
    $found = array();
    foreach ($rows as $r) {
        $f = kop_vsoc_facility((int) $r['id']);
        if (!$f || $f[2] === '' || mb_strtolower($f[0]) === $f[0]) continue;   // no page, or a lower-case placeholder name
        $news->execute(array((int) $r['id']));
        $stories = $news->fetchAll(PDO::FETCH_ASSOC);
        $suits->execute(array((int) $r['id']));
        $cases = array_map(function ($n) { return trim(preg_replace('/\s+\|.*$/s', '', (string) $n)); }, $suits->fetchAll(PDO::FETCH_COLUMN));
        if (!$stories && !$cases) continue;
        $has = array();
        if ($stories) $has[] = count($stories) . (count($stories) === 1 ? ' news story' : ' news stories');
        if ($cases) $has[] = count($cases) . (count($cases) === 1 ? ' lawsuit' : ' lawsuits');
        // The newest story from the press, before the industry's own newsletters.
        $press = array_values(array_filter($stories, function ($s) { return !preg_match("/woodbury|seen 'n heard/i", $s['publication_name'] . ' ' . $s['article_title']); }));
        $latest = $press ? $press[0] : ($stories ? $stories[0] : null);
        $summary = array();
        if ($cases) $summary[] = 'On record: ' . kop_vsoc_list($cases, 3) . '.';
        if ($latest) $summary[] = 'The latest story: "' . trim((string) $latest['article_title']) . '"'
            . (trim((string) $latest['publication_name']) !== '' ? ', ' . trim((string) $latest['publication_name']) : '')
            . (kop_vsoc_date($latest['publication_date']) !== '' ? ', ' . kop_vsoc_date($latest['publication_date']) : '') . '.';
        $found[] = array('n' => count($press) + 3 * count($cases), 'card' => array(
            'key' => (string) (int) $r['id'],
            'fact' => 'Now in our directory: ' . $f[0] . ($f[1] !== '' ? ' (' . $f[1] . ')' : '') . ', with ' . kop_vsoc_list($has) . ' on record.',
            'url' => $f[2], 'url_label' => 'Our page on ' . $f[0],
            'summary' => implode(' ', $summary),
        ));
    }
    usort($found, function ($a, $b) { return $b['n'] <=> $a['n']; });
    return array_column($found, 'card');
}

/**
 * The week's counts in one card, for a weekly recap post: programs added,
 * findings added, records brought up to date (closures, lawsuit and bill
 * updates), documents and the rest (kop_vsoc_number_lines()), old items too,
 * with the programs and records named. A week with anything done is never
 * empty (owner, 2026-10-23).
 */
function kop_vsoc_site_recap($from, $to, array $cards) {
    global $wpdb;
    $pdo = kop_rinbox_pdo();
    $lines = array();
    $named = array();
    $progs = array();
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT name, state FROM facilities_v2 WHERE created_at >= %s AND created_at < %s
        ORDER BY COALESCE(facility_type, '') = '', created_at DESC, id DESC", $from, $to), ARRAY_A) as $r) {
        $name = trim((string) $r['name']);
        if ($name !== '' && mb_strtolower($name) !== $name) $progs[] = $name . (trim((string) $r['state']) !== '' ? ' (' . trim((string) $r['state']) . ')' : '');
    }
    if ($progs) {
        $lines[] = number_format(count($progs)) . (count($progs) === 1 ? ' program added to our directory' : ' programs added to our directory');
        $named[] = 'New in the directory: ' . kop_vsoc_list($progs, 12) . '.';
    }
    $fi = kop_vsoc_log_rows('inspection-highlights', array('approved'), $from, $to);
    if ($fi) {
        $ids = implode(',', array_map('intval', array_column($fi, 'item_key')));
        $s = $pdo->query("SELECT COUNT(*) AS n, COUNT(DISTINCT f.facility_name || '|' || f.state) AS p, COUNT(DISTINCT f.state) AS st FROM inspection_highlights h
            JOIN inspection_facilities f ON f.id = h.facility_id WHERE h.id IN ($ids) AND h.status = 'approved'")->fetch(PDO::FETCH_ASSOC);
        if ((int) $s['n']) $lines[] = number_format((int) $s['n']) . ((int) $s['n'] === 1 ? ' serious finding' : ' serious findings') . ' from state inspections at '
            . number_format((int) $s['p']) . ((int) $s['p'] === 1 ? ' program' : ' programs') . ' in ' . (int) $s['st'] . ((int) $s['st'] === 1 ? ' state' : ' states');
    }
    $closed = array();
    foreach (kop_vsoc_log_rows('closure', array('apply'), $from, $to) as $row) {
        if (isset($cards['closure:' . $row['item_key']])) continue;   // a card of its own already
        $st = $pdo->prepare("SELECT facility_id FROM facility_closure_reports WHERE id = ? AND status = 'applied'");
        $st->execute(array((int) $row['item_key']));
        $f = kop_vsoc_facility((int) $st->fetchColumn());
        if ($f) $closed[] = $f[0];
    }
    if ($closed) {
        $lines[] = number_format(count($closed)) . (count($closed) === 1 ? ' program now recorded as closed' : ' programs now recorded as closed');
        $named[] = 'Now recorded as closed: ' . kop_vsoc_list($closed) . '.';
    }
    $checked = array();
    foreach (kop_vsoc_log_rows('status_checks', array('apply'), $from, $to) as $row) {
        $st = $pdo->prepare("SELECT kind, record_id FROM record_status_proposals WHERE id = ? AND status = 'applied'");
        $st->execute(array((int) $row['item_key']));
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if ($p) $checked[$p['kind'] . '-' . (int) $p['record_id']] = true;
    }
    if ($checked) $lines[] = number_format(count($checked)) . (count($checked) === 1 ? ' lawsuit or bill brought up to date' : ' lawsuits and bills brought up to date');
    $t = $wpdb->prefix . 'posts';
    $docs = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf' AND post_date_gmt >= %s AND post_date_gmt < %s", $from, $to));
    if ($docs) $lines[] = number_format($docs) . ($docs === 1 ? ' document added to our library' : ' documents added to our library');
    foreach (kop_vsoc_number_lines() as $l) {
        $n = count(kop_vsoc_log_rows($l[0], array($l[1]), $from, $to));
        if ($n) $lines[] = number_format($n) . ' ' . ($n === 1 ? $l[2] : $l[3]);
    }
    if (!$lines) return array();
    return array(array(
        'key' => substr($from, 0, 10),
        'fact' => 'This week at Kids Over Profits: ' . kop_vsoc_list(array_slice($lines, 0, 3)) . '.',
        'summary' => ucfirst(implode('; ', $lines)) . '.' . ($named ? ' ' . implode(' ', $named) : ''),
    ));
}
