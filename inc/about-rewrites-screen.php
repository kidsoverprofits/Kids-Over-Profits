<?php
/**
 * KOP Tools > About rewrites: one card per record, the page's About now beside Claude's rewrite and its survivor
 * quotes, the owner's "Needs you" questions as buttons, research and the work log folded, and Publish / Send back
 * to Claude / Undo. The back end is inc/about-rewrites.php; the buttons are js/about-rewrites.js.
 *
 * Tabs: To review (a rewrite waiting), Pick next (search every program and company page and flag the ones Claude
 * writes next, in order, with a note: kop_ar_next()), With Claude (flagged, sent back, or waiting for its first
 * rewrite), Published. Two more list drafts that wait elsewhere (owner, 2026-11-27): Company histories (every
 * /operator/ history still a draft, kop_ar_history_drafts(); Publish is the pencil's own save) and Older AI drafts
 * (the hourly AI write-ups from before 2026-11-13, kop_ar_older_drafts(); Publish is kop_wu_publish(), Send to Claude
 * flags it under Pick next).
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_AR_PER_PAGE = 8;

add_filter('kop_admin_kit_pages', function ($pages) {
    $pages[] = 'kop-about-rewrites';
    return $pages;
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'About rewrites', 'About rewrites', 'manage_options', 'kop-about-rewrites', 'kop_ar_screen');
}, 30);

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'kop-about-rewrites') === false) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('kop-about-rewrites', $uri . '/css/about-rewrites.css', array('kop-admin-kit'), (string) @filemtime($dir . '/css/about-rewrites.css'));
    // Citations read as on the page: numbered [n] with a Sources list, each column (kop-cite-scope) numbered on its own.
    if (file_exists($dir . '/css/citation-previews.css')) {
        wp_enqueue_style('kop-citation-previews', $uri . '/css/citation-previews.css', wp_style_is('kop-colors', 'registered') ? array('kop-colors') : array(), (string) filemtime($dir . '/css/citation-previews.css'));
    }
    if (file_exists($dir . '/js/citation-previews.js')) {
        wp_enqueue_script('kop-citation-previews', $uri . '/js/citation-previews.js', array(), (string) filemtime($dir . '/js/citation-previews.js'), true);
        wp_localize_script('kop-citation-previews', 'kopCitations', array('lookupUrl' => rest_url('kop/v1/citations')));
    }
    wp_enqueue_script('kop-about-rewrites', $uri . '/js/about-rewrites.js', array('kop-admin-kit'), (string) @filemtime($dir . '/js/about-rewrites.js'), true);
    wp_localize_script('kop-about-rewrites', 'KOP_ABOUT_REWRITES', array('nonce' => wp_create_nonce('kop_about_rewrites')));
});

/** The About the page shows now, as HTML. */
function kop_ar_now_html($kind, $id) {
    try {
        $page = $kind === 'operator' ? kop_operator_page_data($id) : kop_facility_page_data($id);
    } catch (Throwable $e) {
        return '<p class="kop-ar-empty">Could not build the page: ' . esc_html($e->getMessage()) . '</p>';
    }
    $about = is_array($page) ? ($page['about'] ?? null) : null;
    $html = is_array($about) && function_exists('kop_pnotes_html') ? kop_pnotes_html($about, 'kop-ar-p') : '';
    return $html !== '' ? $html : '<p class="kop-ar-empty">The page has no About yet.</p>';
}

/** The rewrite's sentences, cited as on the page. */
function kop_ar_sentences_html(array $sentences) {
    return function_exists('kop_pnotes_html') ? kop_pnotes_html(array('paragraphs' => $sentences), 'kop-ar-p') : '';
}

/** The quotes as they will show in the page's Survivor testimony section. */
function kop_ar_quotes_html(array $quotes, $kind, $id) {
    if (!$quotes) return '';
    $out = '<div class="kop-ar-quotes"><h4>Survivor testimony (' . count($quotes) . ')</h4>';
    // One person's quotes together, as the page shows them (kop_testimony_group_by_speaker()).
    $items = array();
    foreach ($quotes as $q) {
        $q['credit'] = implode(', ', array_filter(array($q['who'] ?? '', $q['where'] ?? '', preg_match('/^undated$/i', (string) ($q['date'] ?? '')) ? '' : ($q['date'] ?? '')), 'strlen'));
        $q['text'] = trim((string) ($q['text'] ?? ''));
        $items[] = $q;
    }
    if (function_exists('kop_testimony_group_by_speaker')) $items = kop_testimony_group_by_speaker($items);
    foreach ($items as $i => $q) {
        $join = !empty($q['joins_previous']) && $i > 0;
        $next = !empty($items[$i + 1]['joins_previous']);
        $credit = $q['credit'];
        $where = !empty($q['facility_id']) && (int) $q['facility_id'] !== (int) $id
            ? ' <span class="kop-ar-meta">(goes on ' . esc_html(kop_ar_name('facility', (int) $q['facility_id'])) . ')</span>' : '';
        $text = $q['text'];
        $paras = '';
        foreach (preg_split('/\R\s*\R/', $text) as $para) if (trim($para) !== '') $paras .= '<p>' . esc_html(trim($para)) . '</p>';
        if (!$join && $next) $out .= '<div class="kop-ar-quote-group">';
        $out .= '<blockquote>' . ($text !== '' ? $paras : '<em>Recording, no transcript yet: not shown on the page.</em>')
            . '<footer>' . (!empty($q['url']) ? '<a href="' . esc_url($q['url']) . '" target="_blank" rel="noopener">' . esc_html($credit) . '</a>' : esc_html($credit)) . $where
            . (!empty($q['note']) ? '<br><span class="kop-ar-note">' . esc_html($q['note']) . '</span>' : '')
            . (!empty($q['via']) ? '<br><span class="kop-ar-meta">Read through ' . esc_html($q['via']) . '</span>' : '') . '</footer></blockquote>';
        if (($join || $next) && !$next) $out .= '</div>';
    }
    return $out . '</div>';
}

/** One "Needs you" question with its choices as buttons and a box for an answer of your own. */
function kop_ar_ask_html($key, $i, array $ask, $answer) {
    $answer = is_array($answer) ? $answer : array();
    $btns = '';
    foreach ((array) $ask['choices'] as $c) {
        $on = ($answer['choice'] ?? '') === $c;
        $btns .= '<button type="button" class="button kop-ar-choice' . ($on ? ' is-on' : '') . '" aria-pressed="' . ($on ? 'true' : 'false') . '"'
            . ' data-act="answer" data-i="' . (int) $i . '" data-choice="' . esc_attr($c) . '">' . esc_html($c) . '</button>';
    }
    $id = 'kop-ar-note-' . (int) $key . '-' . (int) $i;
    return '<li class="kop-ar-ask' . ($answer ? ' is-answered' : '') . '" data-i="' . (int) $i . '"><p>' . esc_html($ask['ask']) . '</p>'
        . ($btns !== '' ? '<div class="kop-ar-choices">' . $btns . '</div>' : '')
        . '<div class="kop-ar-own"><label for="' . esc_attr($id) . '">Or your own answer</label>'
        . '<input type="text" id="' . esc_attr($id) . '" value="' . esc_attr($answer['note'] ?? '') . '">'
        . '<button type="button" class="button" data-act="answer-note" data-i="' . (int) $i . '">Save</button></div>'
        . '<p class="kop-ar-saved" aria-live="polite">' . ($answer ? 'Saved' : '') . '</p></li>';
}

/** A company's draft history, read in place, with Publish (the pencil's own save) and a link to the page. */
function kop_ar_history_card(array $h) {
    $title = $h['url'] !== '' ? '<a href="' . esc_url($h['url']) . '" target="_blank" rel="noopener">' . esc_html($h['name']) . '</a>' : esc_html($h['name']);
    $meta = 'Company history &middot; draft, seen by admins only';
    if ($h['alias_of']) $meta .= ' &middot; this record was merged into ' . esc_html(kop_ar_name('operator', $h['alias_of'])) . ', so its page shows that company\'s history instead';
    $out = '<article class="kop-ar-card kop-ar-history" data-key="' . (int) $h['id'] . '">';
    $out .= '<header class="kop-ar-head"><h2>' . $title . '</h2><span class="kop-ar-meta">' . $meta . '</span></header>';
    if ($h['notes'] !== '') $out .= '<section class="kop-ar-needs"><h3>What to check before publishing</h3><p>' . esc_html($h['notes']) . '</p></section>';
    $body = '';
    foreach ($h['paragraphs'] as $para) {
        $table = function_exists('kop_operator_history_table_parse') ? kop_operator_history_table_parse($para) : null;
        if ($table) {
            $body .= kop_operator_history_table_html($table);
        } elseif (preg_match('/^>\s?/', $para)) {
            $body .= '<blockquote class="kop-ar-pq">' . kop_operator_history_paragraph_html(trim(preg_replace('/^>\s?/m', '', $para))) . '</blockquote>';
        } else {
            $body .= '<p class="kop-ar-p">' . kop_operator_history_paragraph_html($para) . '</p>';
        }
    }
    $out .= '<div class="kop-ar-history__text">' . $body . '</div>';
    if ($h['sources']) {
        $li = '';
        foreach ($h['sources'] as $src) {
            $li .= '<li>' . ($src['url'] !== '' ? '<a href="' . esc_url($src['url']) . '" target="_blank" rel="noopener">' . esc_html($src['label']) . '</a>' : esc_html($src['label'])) . '</li>';
        }
        $out .= kop_ak_fold('Sources (' . count($h['sources']) . ')', '<ul class="kop-ar-sources">' . $li . '</ul>', true);
    }
    $edit = $h['url'] !== '' ? ' <a class="button kop-ak-btn kop-ak-btn--quiet" href="' . esc_url($h['url'] . '#history') . '" target="_blank" rel="noopener">Edit on the page</a>' : '';
    return $out . kop_ak_card_actions(kop_ak_button('Publish', 'primary', 'data-act="history-publish"') . $edit) . '</article>';
}

/** An older AI draft beside the page's About now, with Publish (kop_wu_publish()) and Send to Claude for a rewrite. */
function kop_ar_older_card(array $e) {
    $id = (int) $e['id'];
    $key = (int) $e['key'];
    $page = kop_ar_page('facility', $id);
    $name = kop_ar_name('facility', $id);
    if ($page === '' && function_exists('kop_rinbox_facility') && ($fac = kop_rinbox_facility($id))) $name = (string) $fac['name'];
    $d = kop_wu_get($key)['draft'];
    $held = kop_wu_held_rows($id);
    $flag = 0;
    $note = '';
    foreach (kop_ar_next() as $n => $f) if ((int) $f['key'] === $key) { $flag = $n + 1; $note = (string) ($f['note'] ?? ''); }
    $title = $page !== '' ? '<a href="' . esc_url(home_url('/' . $page . '/')) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a>' : esc_html($name);
    $bits = array('Program', 'AI draft' . ($e['at'] !== '' ? ' from ' . esc_html(mysql2date('M j, Y', $e['at'])) : ''));
    if (!empty($d['edited_by'])) $bits[] = 'edited by ' . esc_html($d['edited_by']);
    if ($held) $bits[] = count($held) . ' approved fact' . (count($held) === 1 ? '' : 's') . ' go on the record when you publish';
    if ($page === '') $bits[] = 'no public page yet: publishing makes one';
    if ($flag) $bits[] = '<strong>flagged for Claude, number ' . $flag . '</strong>';
    $out = '<article class="kop-ar-card kop-ar-older" data-key="' . $key . '">';
    $out .= '<header class="kop-ar-head"><h2>' . $title . '</h2><span class="kop-ar-meta">' . implode(' &middot; ', $bits) . '</span></header>';
    if ($e['published'] === 'rewrite') {
        $out .= '<p class="kop-ar-warn">The page has a published rewrite from Claude. Publishing this older draft replaces it.</p>';
    }
    $paras = array();
    foreach ((array) ($d['paragraphs'] ?? array()) as $p) {
        $s = kop_wu_paragraph_sentences($p);
        if ($s) $paras[] = $s;
    }
    $out .= '<div class="kop-ar-cols"><div class="kop-ar-col kop-ar-now kop-cite-scope"><h3>On the page now</h3>' . kop_ar_now_html('facility', $id) . '</div>'
        . '<div class="kop-ar-col kop-ar-new kop-cite-scope"><h3>Older AI draft</h3>' . kop_ar_sentences_html($paras) . '</div></div>';
    $folds = '';
    if (trim((string) ($d['check'] ?? '')) !== '') {
        $folds .= kop_ak_fold('What the AI said to check', '<p>' . esc_html((string) $d['check']) . '</p>');
    }
    if ($held) {
        $li = '';
        foreach ($held as $h) {
            $f = kop_wu_fact($h);
            $li .= '<li>' . esc_html($f['what']) . ($f['url'] !== '' ? ' <a href="' . esc_url($f['url']) . '" target="_blank" rel="noopener">source</a>' : '') . '</li>';
        }
        $folds .= kop_ak_fold('Facts that go on the record when you publish (' . count($held) . ')', '<ul>' . $li . '</ul>');
    }
    $nid = 'kop-ar-older-note-' . $key;
    if ($page === '') {
        $folds .= '<p class="kop-ar-meta">Claude rewrites only records with a page, so this one can be published as written or left here.</p>';
    } else $folds .= kop_ak_fold($flag ? 'Flagged for Claude: change the note' : 'Send to Claude for a rewrite',
        '<p>Flags it under Pick next. Claude writes a rewrite from everything KOP holds, it comes back under To review, and this draft leaves this tab.</p>'
        . '<label for="' . esc_attr($nid) . '" class="screen-reader-text">Note for Claude</label>'
        . '<textarea id="' . esc_attr($nid) . '" rows="3" placeholder="What to focus on (optional)">' . esc_textarea($note) . '</textarea>'
        . kop_ak_button($flag ? 'Save the note' : 'Send to Claude', 'secondary', 'data-act="older-flag"'));
    $out .= '<div class="kop-ar-folds">' . $folds . '</div>';
    return $out . kop_ak_card_actions(kop_ak_button('Publish as written', 'primary', 'data-act="older-publish"')) . '</article>';
}

/** A record's card. $view: review | claude | published. */
function kop_ar_card($e, $view) {
    $kind = $e['kind'];
    $id = (int) $e['id'];
    $key = (int) $e['key'];
    $page = kop_ar_page($kind, $id);
    $name = kop_ar_name($kind, $id);
    $wu = kop_wu_get($key);
    $d = $wu['draft'];
    $p = $wu['published'];
    $title = $page !== '' ? '<a href="' . esc_url(home_url('/' . $page . '/')) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a>' : esc_html($name);
    $sub = $kind === 'operator' ? 'Company' : 'Program';
    $held = $kind === 'facility' && $view !== 'published' ? kop_wu_held_rows($id) : array();
    $out = '<article class="kop-ar-card" data-key="' . $key . '">';

    if ($view === 'published') {
        $when = (string) ($p['at'] ?? '');
        $out .= '<header class="kop-ar-head"><h2>' . $title . '</h2><span class="kop-ar-meta">' . $sub . ' &middot; published '
            . esc_html($when !== '' ? mysql2date('M j, Y', $when) : '') . '</span></header>';
        $out .= kop_ak_fold('Read the published About', '<div class="kop-cite-scope">' . kop_ar_sentences_html((array) ($p['sentences'] ?? array())) . '</div>');
        return $out . kop_ak_card_actions(kop_ak_button('Undo publish', 'quiet', 'data-act="undo"')) . '</article>';
    }

    if ($view === 'claude') {
        $why = array('sent' => 'Sent back to Claude', 'flagged' => 'You flagged it to write next', 'facts' => count($held) . ' approved fact' . (count($held) === 1 ? '' : 's') . ' waiting to be written in',
            'changed' => 'The record changed since its About was written')[$e['why']] ?? '';
        $note = $e['why'] === 'flagged' ? (string) ($e['note'] ?? '') : (string) ($d['sent_back']['note'] ?? '');
        $out .= '<header class="kop-ar-head"><h2>' . $title . '</h2><span class="kop-ar-meta">' . $sub . ' &middot; ' . esc_html($why) . '</span></header>';
        if ($note !== '') $out .= '<p class="kop-ar-sentnote"><strong>Your note:</strong> ' . esc_html($note) . '</p>';
        return $out . '</article>';
    }

    // To review.
    $asks = (array) ($d['needs_you'] ?? array());
    $answers = (array) ($d['answers'] ?? array());
    $out .= '<header class="kop-ar-head"><h2>' . $title . '</h2><span class="kop-ar-meta">' . $sub . ' &middot; written by ' . esc_html((string) ($d['by'] ?? 'Claude'))
        . ($held ? ' &middot; ' . count($held) . ' approved fact' . (count($held) === 1 ? '' : 's') . ' go on the record when you publish' : '') . '</span></header>';
    if ($asks) {
        $out .= '<section class="kop-ar-needs"><h3>Needs you <span class="kop-ar-count">' . count(array_intersect_key($answers, $asks)) . ' of ' . count($asks) . ' answered</span></h3><ol>';
        foreach ($asks as $i => $a) $out .= kop_ar_ask_html($key, $i, $a, $answers[$i] ?? null);
        $out .= '</ol></section>';
    }
    $out .= '<div class="kop-ar-cols"><div class="kop-ar-col kop-ar-now kop-cite-scope"><h3>On the page now</h3>' . kop_ar_now_html($kind, $id) . '</div>'
        . '<div class="kop-ar-col kop-ar-new kop-cite-scope"><h3>Rewrite</h3>' . kop_ar_sentences_html((array) ($d['sentences'] ?? array()))
        . kop_ar_quotes_html((array) ($d['testimony'] ?? array()), $kind, $id) . '</div></div>';
    $folds = '';
    if ($held) {
        $li = '';
        foreach ($held as $h) {
            $f = kop_wu_fact($h);
            $li .= '<li>' . esc_html($f['what']) . ($f['url'] !== '' ? ' <a href="' . esc_url($f['url']) . '" target="_blank" rel="noopener">source</a>' : '') . '</li>';
        }
        $folds .= kop_ak_fold('Facts that go on the record when you publish (' . count($held) . ')', '<ul>' . $li . '</ul>');
    }
    $research = (array) ($d['research'] ?? array());
    $log = trim((string) ($d['log'] ?? ''));
    if ($research || $log) {
        $body = ($research ? '<p><strong>Research to-dos</strong> (for agents, not you)</p><ul>' . implode('', array_map(function ($r) { return '<li>' . esc_html($r) . '</li>'; }, $research)) . '</ul>' : '')
            . ($log !== '' ? '<p><strong>Work log</strong></p><p class="kop-ar-log">' . nl2br(esc_html($log)) . '</p>' : '');
        $folds .= kop_ak_fold('How this was checked' . ($research ? ': ' . count($research) . ' research to-do' . (count($research) === 1 ? '' : 's') : ''), $body);
    }
    $sendid = 'kop-ar-send-' . $key;
    $folds .= kop_ak_fold('Send back to Claude',
        '<p>Claude rewrites it with your answers above and this note, then it comes back here.</p>'
        . '<label for="' . esc_attr($sendid) . '" class="screen-reader-text">Note for Claude</label>'
        . '<textarea id="' . esc_attr($sendid) . '" rows="3" placeholder="What to change (optional)"></textarea>'
        . kop_ak_button('Send back', 'secondary', 'data-act="send-back"'));
    $out .= '<div class="kop-ar-folds">' . $folds . '</div>';
    return $out . kop_ak_card_actions(kop_ak_button('Publish', 'primary', 'data-act="publish"')) . '</article>';
}

function kop_ar_screen() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $view = sanitize_key($_GET['tab'] ?? 'review');
    $view = in_array($view, array('review', 'histories', 'older', 'pick', 'claude', 'published'), true) ? $view : 'review';
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    try {
        $lists = kop_ar_lists();
        $lists['histories'] = kop_ar_history_drafts();
        $lists['older'] = kop_ar_older_drafts();
    } catch (Throwable $e) {
        echo '<div class="wrap kop-ak">' . kop_ak_empty('Could not read the write-ups: ' . $e->getMessage()) . '</div>';
        return;
    }
    $url = function ($tab, $page = 1) {
        return add_query_arg(array('page' => 'kop-about-rewrites', 'tab' => $tab, 'paged' => $page > 1 ? $page : null), admin_url('admin.php'));
    };
    echo '<div class="wrap kop-ak kop-ar">';
    echo kop_ak_header('About rewrites', array(
        'lede' => 'Claude rewrites each page\'s About from everything KOP holds. Read it beside what the page says now, answer its questions, then publish.',
        'steps' => array(
            'Answer the questions under <strong>Needs you</strong>: click a choice, or write your own answer and Save. Nothing else on the card needs you.',
            '<strong>Publish</strong> puts the rewrite on the page, its survivor quotes in the page\'s Survivor testimony section, and any approved facts held for the record onto it.',
            'If it needs changes, open <strong>Send back to Claude</strong>, say what, and send it: Claude rewrites it with your answers and note, and it comes back here.',
            '<strong>Published</strong> lists every rewrite on the site, with Undo, which puts back the About, the quotes and the facts as they were.',
            'To choose what Claude writes next, open <strong>Pick next</strong>: search, press <strong>Flag</strong>, and order the list. Claude takes them from the top.',
            '<strong>Company histories</strong> lists every company page whose history is still a draft. Read it with its sources, then <strong>Publish</strong> (the same as setting it to Published with the pencil), or open the page to edit it.',
            '<strong>Older AI drafts</strong> are the About drafts the AI wrote before Claude took over. <strong>Publish as written</strong>, or <strong>Send to Claude</strong> for a rewrite.',
        ),
    ));
    echo kop_ak_tabs(array(
        'review'    => array('label' => 'To review', 'count' => count($lists['review'])),
        'histories' => array('label' => 'Company histories', 'count' => count($lists['histories']), 'title' => 'Company histories still in draft, seen only by admins on each company page'),
        'older'     => array('label' => 'Older AI drafts', 'count' => count($lists['older']), 'title' => 'About drafts the AI wrote before Claude took over'),
        'pick'      => array('label' => 'Pick next', 'count' => count(kop_ar_next()), 'title' => 'Search every program and company and flag the ones Claude writes next'),
        'claude'    => array('label' => 'With Claude', 'count' => count($lists['claude']), 'title' => 'Flagged, sent back, or waiting for a first rewrite'),
        'published' => array('label' => 'Published', 'count' => count($lists['published'])),
    ), $view, function ($k) use ($url) { return $url($k); });
    if ($view === 'pick') {
        echo kop_ar_pick_html();
        echo '</div>';
        return;
    }
    $items = array_values($lists[$view]);
    $pages = max(1, (int) ceil(count($items) / KOP_AR_PER_PAGE));
    $paged = min($paged, $pages);
    $slice = array_slice($items, ($paged - 1) * KOP_AR_PER_PAGE, KOP_AR_PER_PAGE);
    if (!$slice) {
        echo kop_ak_empty(array('review' => 'Nothing to review: every rewrite Claude has sent is published or back with Claude.',
            'claude' => 'Nothing waiting for Claude.', 'published' => 'No rewrites published yet.',
            'histories' => 'No company history is waiting: every written one is published.', 'older' => 'No older AI drafts are left.')[$view]);
    }
    foreach ($slice as $e) {
        if ($view === 'histories') echo kop_ar_history_card($e);
        elseif ($view === 'older') echo kop_ar_older_card($e);
        else echo kop_ar_card($e, $view);
    }
    echo kop_ak_pager($paged, $pages, count($items), 'records', function ($n) use ($url, $view) { return $url($view, $n); });
    echo '</div>';
}

/**
 * The Pick next tab: the flagged list (Claude writes these first, top down) and a search over every program and company
 * page. The rows are drawn by js/about-rewrites.js from the 'pick-search' and 'pick-list' answers; this is the frame.
 */
function kop_ar_pick_html() {
    $out = '<section class="kop-ar-pick" aria-labelledby="kop-ar-flagged-h">';
    $out .= '<h2 id="kop-ar-flagged-h" class="kop-ar-pick__h">Flagged for Claude <span class="kop-ak-count" data-flag-count>' . count(kop_ar_next()) . '</span></h2>';
    $out .= '<p class="kop-ar-meta">Claude writes these first, top to bottom. Each one leaves this list when its rewrite comes in under To review.</p>';
    $out .= '<ol class="kop-ar-flagged" data-flagged aria-live="polite"></ol>';
    $out .= '<p class="kop-ar-empty" data-flagged-empty hidden>Nothing flagged yet. Search below and press Flag.</p>';
    $out .= '</section>';

    $out .= '<section class="kop-ar-pick" aria-labelledby="kop-ar-find-h">';
    $out .= '<h2 id="kop-ar-find-h" class="kop-ar-pick__h">Find records</h2>';
    $out .= '<form class="kop-ar-find" data-find role="search" onsubmit="return false">'
        . '<label class="screen-reader-text" for="kop-ar-q">Search</label>'
        . '<input type="search" id="kop-ar-q" name="q" class="kop-ak-search" autocomplete="off" placeholder="Name, other name, city, state (UT), company, or id">'
        . '<label><span class="screen-reader-text">Kind</span><select name="kind"><option value="">Programs and companies</option><option value="facility">Programs</option><option value="operator">Companies</option></select></label>'
        . '<label><span class="screen-reader-text">Show</span><select name="show"><option value="none">No rewrite yet</option><option value="">Any</option><option value="published">Published rewrites</option></select></label>'
        . '<label><span class="screen-reader-text">Sort</span><select name="sort"><option value="">Best match</option><option value="news">Most news</option><option value="lawsuits">Most lawsuits</option><option value="name">A to Z</option></select></label>'
        . '</form>';
    $out .= '<p class="kop-ar-meta" data-find-status aria-live="polite"></p>';
    $out .= '<table class="widefat striped kop-ar-results"><thead><tr><th scope="col">Record</th><th scope="col" class="num">News</th><th scope="col" class="num">Lawsuits</th>'
        . '<th scope="col">Now</th><th scope="col"><span class="screen-reader-text">Flag</span></th></tr></thead><tbody data-results></tbody></table>';
    return $out . '</section>';
}

add_action('wp_ajax_kop_about_rewrites', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Not allowed.', 403);
    check_ajax_referer('kop_about_rewrites', 'nonce');
    $act = sanitize_key($_POST['act'] ?? '');
    $key = (int) ($_POST['key'] ?? 0);
    $who = wp_get_current_user()->display_name ?: wp_get_current_user()->user_login;
    try {
        switch ($act) {
            case 'answer':
                $a = kop_ar_answer($key, (int) ($_POST['i'] ?? -1), wp_unslash($_POST['choice'] ?? ''), wp_unslash($_POST['note'] ?? ''));
                wp_send_json_success(array('message' => $a ? 'Saved' : 'Cleared', 'answer' => $a));
            case 'publish':
                wp_send_json_success(array('message' => kop_ar_publish($key, $who), 'undo' => 'undo'));
            case 'undo':
                wp_send_json_success(array('message' => kop_ar_undo($key, $who)));
            case 'send-back':
                kop_ar_send_back($key, wp_unslash($_POST['note'] ?? ''), $who);
                wp_send_json_success(array('message' => 'Sent back to Claude. It is under With Claude until the new rewrite comes in.'));
            case 'history-publish':
                wp_send_json_success(array('message' => kop_ar_history_set_status($key, 'published'), 'undo' => 'history-undo'));
            case 'history-undo':
                wp_send_json_success(array('message' => kop_ar_history_set_status($key, 'draft')));
            case 'older-publish':
                wp_send_json_success(array('message' => kop_ar_older_publish($key, $who), 'undo' => 'older-undo'));
            case 'older-undo':
                wp_send_json_success(array('message' => kop_ar_older_undo($key, $who)));
            case 'older-flag':
                $n = kop_ar_flag($key, wp_unslash($_POST['note'] ?? ''), $who);
                wp_send_json_success(array('message' => 'Sent to Claude: number ' . $n . ' on the Pick next list. The rewrite comes back under To review.'));
            case 'pick-search':
                $rows = kop_ar_search(array('q' => wp_unslash($_POST['q'] ?? ''), 'kind' => sanitize_key($_POST['kind'] ?? ''),
                    'show' => sanitize_key($_POST['show'] ?? ''), 'sort' => sanitize_key($_POST['sort'] ?? ''), 'limit' => 60));
                wp_send_json_success(array('rows' => $rows, 'total' => (int) ($GLOBALS['kop_ar_search_total'] ?? count($rows))));
            case 'pick-list':
                wp_send_json_success(array('flagged' => kop_ar_flagged_rows()));
            case 'flag':
                $n = kop_ar_flag($key, wp_unslash($_POST['note'] ?? ''), $who);
                wp_send_json_success(array('message' => 'Flagged: number ' . $n . ' on Claude\'s list.', 'flagged' => kop_ar_flagged_rows()));
            case 'unflag':
                kop_ar_unflag($key);
                wp_send_json_success(array('message' => 'Flag taken off.', 'flagged' => kop_ar_flagged_rows()));
            case 'flag-move':
                kop_ar_flag_move($key, sanitize_key($_POST['to'] ?? ''));
                wp_send_json_success(array('flagged' => kop_ar_flagged_rows()));
        }
        wp_send_json_error('Unknown action.');
    } catch (Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});
