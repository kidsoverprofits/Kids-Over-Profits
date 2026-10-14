<?php
/**
 * One look for the KOP Tools screens (css/admin-kit.css, js/admin-kit.js).
 *
 * The rules every screen follows (docs/ADMIN-UI.md has them in full):
 *  - A decision made on each item (Add / Skip, Approve / Reject; three at most)
 *    is a visible button, the main one first and always the primary colour.
 *  - Everything else on an item goes in one "More" menu (kop_ak_more()) or a
 *    fold (kop_ak_fold()), never a fourth button in the row.
 *  - A <select> only picks a value; it never runs an action by itself.
 *  - Filters sit in one toolbar over the list (kop_ak_filters()): views as tabs
 *    with counts, then search, then dropdowns. They apply on change, live in
 *    the address, and the list pages (kop_ak_pager()).
 *  - Undo has one look (kop_ak_button(.., 'quiet')) and follows every action
 *    on the result line (js/admin-kit.js), as well as the screen's Added tab.
 *
 * A screen opts in with add_filter('kop_admin_kit_pages', ...) naming its
 * page slug, so the kit's files load in its <head>.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_ak_pages() {
    return (array) apply_filters('kop_admin_kit_pages', array());
}

add_action('admin_enqueue_scripts', function () {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page === '' || !in_array($page, kop_ak_pages(), true)) {
        return;
    }
    kop_ak_enqueue();
});

function kop_ak_enqueue() {
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    if (file_exists($dir . '/css/colors.css') && !wp_style_is('kop-colors', 'registered')) {
        wp_register_style('kop-colors', $uri . '/css/colors.css', array(), filemtime($dir . '/css/colors.css'));
    }
    wp_enqueue_style('kop-admin-kit', $uri . '/css/admin-kit.css', wp_style_is('kop-colors', 'registered') ? array('kop-colors') : array(),
        filemtime($dir . '/css/admin-kit.css'));
    wp_enqueue_script('kop-admin-kit', $uri . '/js/admin-kit.js', array(), filemtime($dir . '/js/admin-kit.js'), true);
    wp_localize_script('kop-admin-kit', 'KOP_ADMIN_KIT', array('ajax' => admin_url('admin-ajax.php')));
}

/**
 * The top of a screen: its title, one line saying what it is for, and the
 * how-to steps folded under "How this works" (closed once it has been read:
 * the kit remembers that per screen in the browser).
 * $o: lede (html), steps (list of html), help (html after the steps), notices (html).
 */
function kop_ak_header($title, array $o = array()) {
    $out = '<header class="kop-ak-header"><h1>' . esc_html($title) . '</h1>';
    if (!empty($o['lede'])) {
        $out .= '<p class="kop-ak-lede">' . $o['lede'] . '</p>';
    }
    $out .= '</header>';
    $out .= $o['notices'] ?? '';
    if (!empty($o['steps']) || !empty($o['help'])) {
        $out .= '<details class="kop-ak-help" data-kop-ak-help="' . esc_attr(sanitize_key($title)) . '"><summary>How this works</summary><div class="kop-ak-help__body">';
        if (!empty($o['steps'])) {
            $out .= '<ol>';
            foreach ($o['steps'] as $step) {
                $out .= '<li>' . $step . '</li>';
            }
            $out .= '</ol>';
        }
        $out .= ($o['help'] ?? '') . '</div></details>';
    }
    return $out;
}

/**
 * A strip of facts about the queue (counts, last run) with the screen's own
 * tools at its end: small secondary buttons, never in the list's way.
 */
function kop_ak_status($html, $tools = '') {
    return '<div class="kop-ak-status"><div class="kop-ak-status__text">' . $html . '</div>'
        . ($tools !== '' ? '<div class="kop-ak-status__tools">' . $tools . '</div>' : '') . '</div>';
}

/**
 * Views of the list as tabs, each with its count. $tabs: key => label or
 * {label, count, title}; $url: fn(key) -> address. $o['kind'] = 'segments'
 * draws the smaller second row (Waiting / Added / Skipped under the kinds).
 */
function kop_ak_tabs(array $tabs, $current, callable $url, array $o = array()) {
    $kind = ($o['kind'] ?? '') === 'segments' ? 'segments' : 'tabs';
    $out = '<nav class="kop-ak-' . $kind . '" aria-label="' . esc_attr($o['label'] ?? 'Views') . '">';
    foreach ($tabs as $k => $t) {
        $t = is_array($t) ? $t : array('label' => $t);
        $on = (string) $k === (string) $current;
        $out .= '<a class="kop-ak-tab' . ($on ? ' is-current' : '') . '" href="' . esc_url($url($k)) . '"'
            . ($on ? ' aria-current="page"' : '') . (!empty($t['title']) ? ' title="' . esc_attr($t['title']) . '"' : '') . '>'
            . esc_html($t['label'])
            . (isset($t['count']) ? ' <span class="kop-ak-count">' . esc_html(number_format((int) $t['count'])) . '</span>' : '')
            . '</a>';
    }
    return $out . '</nav>';
}

/**
 * The filter toolbar: a GET form that applies itself (a dropdown on change,
 * the search box on Enter), keeps $hidden (page, tab) and shows "Clear
 * filters" when anything is set. $fields, in order:
 *   {type: search, name, value, placeholder, label}
 *   {type: select, name, value, label, all ('Every kind'), options {value: label}}
 */
function kop_ak_filters(array $hidden, array $fields, $clear_url = '') {
    $out = '<form method="get" class="kop-ak-filters" data-kop-ak-filters role="search">';
    foreach ($hidden as $k => $v) {
        if ($v !== '' && $v !== null) {
            $out .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
    }
    $set = false;
    foreach ($fields as $f) {
        $value = (string) ($f['value'] ?? '');
        $set = $set || $value !== '';
        $label = esc_attr($f['label'] ?? $f['name']);
        if (($f['type'] ?? '') === 'search') {
            $out .= '<input type="search" class="kop-ak-search" name="' . esc_attr($f['name']) . '" value="' . esc_attr($value) . '"'
                . ' placeholder="' . esc_attr($f['placeholder'] ?? 'Search') . '" aria-label="' . $label . '">';
        } else {
            $out .= '<select name="' . esc_attr($f['name']) . '" aria-label="' . $label . '"' . ($value !== '' ? ' class="is-set"' : '') . '>';
            if (isset($f['all'])) {
                $out .= '<option value="">' . esc_html($f['all']) . '</option>';
            }
            foreach ((array) $f['options'] as $k => $l) {
                $out .= '<option value="' . esc_attr($k) . '"' . selected($value, (string) $k, false) . '>' . esc_html($l) . '</option>';
            }
            $out .= '</select>';
        }
    }
    $out .= '<button type="submit" class="button kop-ak-btn kop-ak-btn--secondary kop-ak-apply">Apply</button>';
    if ($set && $clear_url !== '') {
        $out .= '<a class="kop-ak-clear" href="' . esc_url($clear_url) . '">Clear filters</a>';
    }
    return $out . '</form>';
}

/**
 * Pages of a list: Previous, the first, the last and two either side of this
 * one, Next, and how many there are. $url: fn(page) -> address. '' for one page.
 */
function kop_ak_pager($paged, $pages, $total, $noun, callable $url) {
    $paged = max(1, (int) $paged);
    $pages = (int) $pages;
    if ($pages <= 1) {
        return '';
    }
    $out = '<nav class="kop-ak-pager" aria-label="Pages">';
    $out .= $paged > 1 ? '<a class="kop-ak-page kop-ak-page--step" href="' . esc_url($url($paged - 1)) . '" rel="prev">&lsaquo; Previous</a>'
        : '<span class="kop-ak-page kop-ak-page--step is-off">&lsaquo; Previous</span>';
    $last = 0;
    for ($n = 1; $n <= $pages; $n++) {
        if ($n !== 1 && $n !== $pages && abs($n - $paged) > 2) {
            continue;
        }
        if ($last && $n > $last + 1) {
            $out .= '<span class="kop-ak-page is-gap">&hellip;</span>';
        }
        $out .= $n === $paged ? '<span class="kop-ak-page is-current" aria-current="page">' . $n . '</span>'
            : '<a class="kop-ak-page" href="' . esc_url($url($n)) . '">' . $n . '</a>';
        $last = $n;
    }
    $out .= $paged < $pages ? '<a class="kop-ak-page kop-ak-page--step" href="' . esc_url($url($paged + 1)) . '" rel="next">Next &rsaquo;</a>'
        : '<span class="kop-ak-page kop-ak-page--step is-off">Next &rsaquo;</span>';
    return $out . '<span class="kop-ak-pager__total">' . number_format((int) $total) . ' ' . esc_html($noun) . '</span></nav>';
}

/**
 * A button. $variant: primary (the main decision), secondary, danger (reject,
 * skip, delete), quiet (undo, put back, small tools). $attrs is raw attribute
 * text (data-act="apply"), never a class: those go in $class.
 */
function kop_ak_button($label, $variant = 'secondary', $attrs = '', $class = '') {
    $variant = in_array($variant, array('primary', 'secondary', 'danger', 'quiet'), true) ? $variant : 'secondary';
    return '<button type="button" class="button kop-ak-btn kop-ak-btn--' . $variant . ($class !== '' ? ' ' . esc_attr($class) : '') . '"'
        . ($attrs !== '' ? ' ' . trim($attrs) : '') . '>'
        . esc_html($label) . '</button>';
}

/** The "More" menu: the actions an item seldom needs, one per line. $items: html buttons. */
function kop_ak_more(array $items, $label = 'More') {
    $items = array_filter($items);
    if (!$items) {
        return '';
    }
    return '<details class="kop-ak-more"><summary class="kop-ak-btn kop-ak-btn--secondary">' . esc_html($label) . '</summary>'
        . '<div class="kop-ak-menu" role="menu">' . implode('', $items) . '</div></details>';
}

/** A fold: a picker with its own button (another record, create one), closed until wanted. */
function kop_ak_fold($summary, $body, $open = false, $class = '') {
    return '<details class="kop-ak-fold' . ($class !== '' ? ' ' . esc_attr($class) : '') . '"' . ($open ? ' open' : '') . '>'
        . '<summary>' . $summary . '</summary><div class="kop-ak-fold__body">' . $body . '</div></details>';
}

/** The bar that stays at the top while the list scrolls: page-wide actions and their progress. */
function kop_ak_bar($html) {
    return '<div class="kop-ak-bar">' . $html . '<span class="kop-ak-progress" aria-live="polite"></span></div>';
}

/** What an empty view says. */
function kop_ak_empty($text) {
    return '<div class="kop-ak-empty">' . esc_html($text) . '</div>';
}

/**
 * The head of a card of ticked rows: its title, the links and notes under it,
 * and "Tick all" (js/admin-kit.js keeps it in step with the rows).
 */
function kop_ak_card_head($title_html, $sub_html = '', $tickall = true) {
    return '<header class="kop-ak-card__head"><div class="kop-ak-card__title"><h2>' . $title_html . '</h2>'
        . ($sub_html !== '' ? '<div class="kop-ak-card__sub">' . $sub_html . '</div>' : '') . '</div>'
        . ($tickall ? '<label class="kop-ak-tickall"><input type="checkbox" class="kop-ak-tickall__box"> Tick all</label>' : '')
        . '</header>';
}

/** One row's tick box. */
function kop_ak_pick($checked) {
    return '<td class="kop-ak-check"><input type="checkbox" class="kop-ak-pick"' . ($checked ? ' checked' : '') . ' aria-label="Select"></td>';
}

/** The foot of a card: its decisions, then the result line the script fills in (with Undo). */
function kop_ak_card_actions($html) {
    return '<footer class="kop-ak-card__actions"><div class="kop-ak-card__buttons">' . $html . '</div>'
        . '<div class="kop-ak-result" aria-live="polite"></div></footer>';
}
