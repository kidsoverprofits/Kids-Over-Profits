<?php
/**
 * KOP Tools > SEO Audit: what every page in the Yoast sitemaps tells search
 * engines (live title, description, canonical, breadcrumb trail) next to the
 * description and focus keyphrase stored in the post's Yoast fields, with the
 * problems flagged: missing, generic and duplicate descriptions, sparse
 * facility pages that say only name and state, missing breadcrumbs.
 *
 * Read-only. The crawl runs off the site ((kop-pipeline) scripts/seo-audit.py,
 * which also reads the stored Yoast fields from the production mirror) and its
 * audit.json is copied to wp-content/uploads/kop-seo-audit/audit.json; this
 * screen only reads that file. Yoast's own dashboard reads the stored fields,
 * not the theme's wpseo_* filters (inc/seo.php), so a page can be fine live and
 * still warn there: both are shown. docs/plan/4.7 item 8; items 9-11 act on it.
 * Tested by scripts/test-seo-audit.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('kop_admin_kit_pages', function ($pages) {
    $pages[] = 'kop-seo-audit';
    return $pages;
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'SEO Audit', 'SEO Audit', 'manage_options', 'kop-seo-audit', 'kop_seo_audit_page');
}, 30);

/** Where the crawl's audit.json is put on the server. */
function kop_seo_audit_path() {
    if (!empty($GLOBALS['kop_seo_audit_path'])) return (string) $GLOBALS['kop_seo_audit_path'];
    $up = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : array('basedir' => WP_CONTENT_DIR . '/uploads');
    return rtrim((string) $up['basedir'], '/\\') . '/kop-seo-audit/audit.json';
}

/** The audit ({generated, mirror, counts, rows}) or null when there is none yet. */
function kop_seo_audit_load($path = null) {
    $path = $path ?: kop_seo_audit_path();
    if (!is_file($path)) return null;
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) && isset($data['rows']) && is_array($data['rows']) ? $data : null;
}

/**
 * Every flag the crawl writes: code => [label, what it means]. Codes the crawl
 * adds later (http_404, ...) still show, by their code.
 */
function kop_seo_audit_flags() {
    return array(
        'no_desc'           => array('No description', 'The page gives search engines no description, so Google picks words from the page.'),
        'sparse_facility'   => array('Sparse facility', 'The description says only the name, the kind of program and the place.'),
        'thin_facility'     => array('Thin facility', 'The description has the place and kind but no operator and no years.'),
        'sparse_operator'   => array('Sparse company', 'The company page has no description, or one short sentence.'),
        'template_desc'     => array('Template description', 'The description is the fixed wording every page of its kind shares (state hubs, map, news feed) or the site default.'),
        'dup_desc'          => array('Same description', 'Another page has the same description.'),
        'dup_title'         => array('Same title', 'Another page has the same title.'),
        'short_desc'        => array('Short description', 'Under 70 characters.'),
        'long_desc'         => array('Long description', 'Over 158 characters; Google cuts it.'),
        'long_title'        => array('Long title', 'Over 65 characters; Google cuts it.'),
        'no_stored_desc'    => array('No Yoast description', 'A post or page with no description in its Yoast field, so Yoast warns even when the live page has one.'),
        'no_focuskw'        => array('No focus keyphrase', 'A post or page with no Yoast focus keyphrase.'),
        'stored_differs'    => array('Yoast field differs', 'The description in the Yoast field is not the one the live page prints.'),
        'no_canonical'      => array('No canonical', 'The page names no canonical address.'),
        'canonical_mismatch'=> array('Canonical elsewhere', 'The canonical address is not the page\'s own.'),
        'noindex'           => array('Noindex in sitemap', 'The sitemap lists it but the page asks not to be indexed.'),
        'no_breadcrumb'     => array('No breadcrumb', 'No BreadcrumbList in the structured data.'),
        'breadcrumb_shallow'=> array('Breadcrumb is Home only', 'The trail has only Home.'),
        'redirect'          => array('Redirects', 'The sitemap address redirects somewhere else.'),
        'critical_error'    => array('Critical error', 'The page printed WordPress\'s "critical error".'),
    );
}

/** A flag's [label, meaning], also for codes the list above lacks. */
function kop_seo_audit_flag($code) {
    $all = kop_seo_audit_flags();
    if (isset($all[$code])) return $all[$code];
    if (preg_match('/^http_(\d{3})$/', (string) $code, $m)) return array('HTTP ' . $m[1], 'The page answered ' . $m[1] . ' instead of 200.');
    return array((string) $code, '');
}

/** Rows matching kind ('' = any), flag ('' = any flag, 'none' = no flags, else one code) and words in the URL, title or description. */
function kop_seo_audit_filter(array $rows, $kind = '', $flag = '', $q = '') {
    $q = mb_strtolower(trim((string) $q));
    return array_values(array_filter($rows, function ($r) use ($kind, $flag, $q) {
        $flags = (array) ($r['flags'] ?? array());
        if ($kind !== '' && (string) ($r['kind'] ?? '') !== $kind) return false;
        if ($flag === 'none' && $flags) return false;
        if ($flag !== '' && $flag !== 'none' && !in_array($flag, $flags, true)) return false;
        if ($q !== '') {
            $hay = mb_strtolower(($r['url'] ?? '') . ' ' . ($r['title'] ?? '') . ' ' . ($r['desc'] ?? ''));
            if (mb_strpos($hay, $q) === false) return false;
        }
        return true;
    }));
}

/** [code => count] over $rows, in the order of kop_seo_audit_flags(), others after. */
function kop_seo_audit_flag_counts(array $rows) {
    $n = array();
    foreach ($rows as $r) foreach ((array) ($r['flags'] ?? array()) as $f) $n[$f] = ($n[$f] ?? 0) + 1;
    $out = array();
    foreach (array_keys(kop_seo_audit_flags()) as $code) if (!empty($n[$code])) $out[$code] = $n[$code];
    foreach ($n as $code => $c) if (!isset($out[$code])) $out[$code] = $c;
    return $out;
}

/** One row as a card: address, flags, then the live words beside the stored ones. */
function kop_seo_audit_card(array $r) {
    $url = (string) ($r['url'] ?? '');
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
    $out = '<article class="kop-seoa-card"><header class="kop-seoa-card__head">'
        . '<a class="kop-seoa-url" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html(rawurldecode($path)) . '</a>'
        . ' <span class="kop-seoa-kind">' . esc_html((string) ($r['kind'] ?? '')) . '</span>';
    if (!empty($r['post_id'])) {
        $out .= ' <a class="kop-seoa-edit" href="' . esc_url(admin_url('post.php?post=' . (int) $r['post_id'] . '&action=edit')) . '">Edit post ' . (int) $r['post_id'] . '</a>';
    }
    $out .= '</header>';
    $flags = (array) ($r['flags'] ?? array());
    if ($flags) {
        $out .= '<ul class="kop-seoa-flags">';
        foreach ($flags as $f) {
            $l = kop_seo_audit_flag($f);
            $out .= '<li class="kop-seoa-flag" title="' . esc_attr($l[1]) . '">' . esc_html($l[0]) . '</li>';
        }
        $out .= '</ul>';
    }
    $line = function ($label, $value, $note = '') {
        $value = (string) $value;
        return '<dt>' . esc_html($label) . '</dt><dd>' . ($value === '' ? '<em class="kop-seoa-none">none</em>' : esc_html($value))
            . ($note !== '' ? ' <span class="kop-seoa-note">' . esc_html($note) . '</span>' : '') . '</dd>';
    };
    $desc = (string) ($r['desc'] ?? '');
    $out .= '<dl class="kop-seoa-lines">'
        . $line('Title', $r['title'] ?? '', isset($r['title']) ? mb_strlen((string) $r['title']) . ' chars' : '')
        . $line('Description', $desc, $desc !== '' ? mb_strlen($desc) . ' chars' : '');
    if (is_array($r['stored'] ?? null)) {
        $s = $r['stored'];
        $out .= $line('Yoast field', $s['metadesc'] ?? '') . $line('Focus keyphrase', $s['focuskw'] ?? '');
    }
    $canon = (string) ($r['canonical'] ?? '');
    if ($canon !== '' && rtrim($canon, '/') !== rtrim((string) ($r['final_url'] ?? $url), '/')) $out .= $line('Canonical', $canon);
    $trail = array();
    foreach ((array) ($r['breadcrumb'] ?? array()) as $b) $trail[] = (string) ($b['name'] ?? '');
    $out .= $line('Breadcrumb', implode(' > ', $trail));
    if (!empty($r['status']) && (int) $r['status'] !== 200) $out .= $line('Answer', 'HTTP ' . (int) $r['status']);
    $out .= '</dl>';
    foreach (array('dup_desc_with' => 'Same description as', 'dup_title_with' => 'Same title as') as $k => $label) {
        $with = (array) ($r[$k] ?? array());
        if (!$with) continue;
        $links = array();
        foreach ($with as $u) $links[] = '<a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html(rawurldecode((string) (parse_url($u, PHP_URL_PATH) ?: $u))) . '</a>';
        $out .= '<p class="kop-seoa-dups">' . esc_html($label) . ': ' . implode(', ', $links) . '</p>';
    }
    return $out . '</article>';
}

function kop_seo_audit_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $audit = kop_seo_audit_load();
    $base = admin_url('admin.php?page=kop-seo-audit');

    echo '<div class="wrap kop-ak kop-seoa">';
    echo kop_ak_header('SEO Audit', array(
        'lede' => 'What every page in the sitemaps tells Google: its title, its description and its breadcrumb trail, with the pages that say too little, say the same as another page, or will make Yoast warn.',
        'steps' => array(
            'Pick a problem in the list at the top, or a kind of page in the tabs. Each card shows what the live page prints and, for posts and pages, what is stored in its Yoast fields.',
            'Hover a red label for what it means. <em>Edit post</em> opens the post in the editor.',
            'Nothing here changes a page. Writing the missing descriptions comes next, on its own review screen.',
        ),
        'help' => '<p>Yoast\'s dashboard and its editor check read the description and keyphrase stored in the post. The site writes descriptions for many pages as it prints them, so a page can be fine in Google and still warn in Yoast: <em>No Yoast description</em> marks those. '
            . 'Facility and company pages are built from the records and have no post, so they have no Yoast fields; their descriptions improve when the record does.</p>'
            . '<p>The audit is a snapshot: the crawl runs off the site and its results are copied here.</p>',
    ));
    if (!$audit) {
        echo kop_ak_empty('No audit has been copied to the site yet.') . '</div>';
        return;
    }

    $rows = $audit['rows'];
    $kind = sanitize_key($_GET['kind'] ?? '');
    $flag = sanitize_key($_GET['flag'] ?? '');
    $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $per = 50;

    $when = function ($iso) { $t = strtotime((string) $iso); return $t ? wp_date('M j, Y g:i a', $t) : (string) $iso; };
    $flagged = count(array_filter($rows, function ($r) { return !empty($r['flags']); }));
    echo kop_ak_status('Crawled ' . esc_html($when($audit['generated'] ?? '')) . ' &nbsp;&middot;&nbsp; '
        . number_format(count($rows)) . ' pages, ' . number_format($flagged) . ' with something flagged'
        . (!empty($audit['mirror']) ? ' &nbsp;&middot;&nbsp; Yoast fields as of ' . esc_html($when($audit['mirror'])) : ''));

    // Problems, with counts over the kind picked: one click narrows the list to it.
    $in_kind = $kind === '' ? $rows : kop_seo_audit_filter($rows, $kind);
    $counts = kop_seo_audit_flag_counts($in_kind);
    if ($counts) {
        echo '<ul class="kop-seoa-summary">';
        foreach ($counts as $code => $n) {
            $l = kop_seo_audit_flag($code);
            $args = array_filter(array('page' => 'kop-seo-audit', 'kind' => $kind, 'flag' => $code));
            echo '<li' . ($flag === $code ? ' class="is-current"' : '') . '><a href="' . esc_url(admin_url('admin.php?' . http_build_query($args))) . '" title="' . esc_attr($l[1]) . '">'
                . esc_html($l[0]) . ' <span class="kop-ak-count">' . number_format($n) . '</span></a></li>';
        }
        echo '</ul>';
    }

    $kinds = array();
    foreach ($rows as $r) { $k = (string) ($r['kind'] ?? ''); $kinds[$k] = ($kinds[$k] ?? 0) + 1; }
    arsort($kinds);
    $tabs = array('' => array('label' => 'All', 'count' => count($rows)));
    foreach ($kinds as $k => $n) $tabs[$k] = array('label' => ucfirst($k === 'operator' ? 'company' : $k), 'count' => $n);
    echo kop_ak_tabs($tabs, $kind, function ($k) use ($flag) {
        return admin_url('admin.php?' . http_build_query(array_filter(array('page' => 'kop-seo-audit', 'kind' => $k, 'flag' => $flag))));
    }, array('label' => 'Kinds of page'));

    $options = array('none' => 'Nothing flagged');
    foreach ($counts as $code => $n) $options[$code] = kop_seo_audit_flag($code)[0];
    echo kop_ak_filters(array('page' => 'kop-seo-audit', 'kind' => $kind), array(
        array('type' => 'select', 'name' => 'flag', 'value' => $flag, 'label' => 'Problem', 'all' => 'Any problem or none', 'options' => $options),
        array('type' => 'search', 'name' => 'q', 'value' => $q, 'placeholder' => 'Address, title or description', 'label' => 'Search'),
    ), add_query_arg('kind', $kind, $base));

    $list = kop_seo_audit_filter($rows, $kind, $flag, $q);
    $pages = (int) ceil(count($list) / $per);
    $paged = min($paged, max(1, $pages));
    $pager = kop_ak_pager($paged, $pages, count($list), 'pages', function ($n) use ($kind, $flag, $q) {
        return admin_url('admin.php?' . http_build_query(array_filter(array('page' => 'kop-seo-audit', 'kind' => $kind, 'flag' => $flag, 'q' => $q, 'paged' => $n > 1 ? $n : ''))));
    });
    if (!$list) {
        echo kop_ak_empty('No page matches.');
    } else {
        echo $pager . '<div class="kop-seoa-list">';
        foreach (array_slice($list, ($paged - 1) * $per, $per) as $r) echo kop_seo_audit_card($r);
        echo '</div>' . $pager;
    }
    ?>
    <style>
        .kop-seoa-summary { display:flex; flex-wrap:wrap; gap:6px; margin:10px 0; padding:0; list-style:none; max-width:1100px; }
        .kop-seoa-summary a { display:inline-block; padding:4px 10px; border:1px solid #c3c4c7; border-radius:3px; background:var(--kop-white, #fff); color:var(--kop-text-primary, #1d2327); text-decoration:none; }
        .kop-seoa-summary .is-current a { border-color:var(--kop-navy, #000080); box-shadow:inset 0 0 0 1px var(--kop-navy, #000080); }
        .kop-seoa-list { display:grid; gap:10px; max-width:1100px; margin:10px 0; }
        .kop-seoa-card { padding:10px 14px; background:var(--kop-white, #fff); border:1px solid #c3c4c7; border-radius:3px; }
        .kop-seoa-card__head { display:flex; flex-wrap:wrap; gap:4px 10px; align-items:baseline; overflow-wrap:anywhere; }
        .kop-seoa-url { font-weight:600; color:var(--kop-navy, #000080); }
        .kop-seoa-kind, .kop-seoa-note, .kop-seoa-none { color:var(--kop-text-muted, #4A5568); font-size:12px; }
        .kop-seoa-edit { font-size:12px; color:var(--kop-navy, #000080); }
        .kop-seoa-flags { display:flex; flex-wrap:wrap; gap:4px; margin:6px 0; padding:0; list-style:none; }
        .kop-seoa-flag { padding:1px 8px; border:1px solid #b32d2e; border-radius:10px; color:#8a1f1f; background:var(--kop-white, #fff); font-size:12px; cursor:help; }
        .kop-seoa-lines { display:grid; grid-template-columns:max-content 1fr; gap:2px 12px; margin:6px 0 0; }
        .kop-seoa-lines dt { font-weight:600; color:var(--kop-text-primary, #1d2327); }
        .kop-seoa-lines dd { margin:0; color:var(--kop-text-primary, #1d2327); overflow-wrap:anywhere; }
        .kop-seoa-dups { margin:6px 0 0; font-size:12px; color:var(--kop-text-muted, #4A5568); overflow-wrap:anywhere; }
        .kop-seoa-dups a { color:var(--kop-navy, #000080); }
        @media (max-width:600px) { .kop-seoa-lines { grid-template-columns:1fr; } }
    </style>
    <?php
    echo '</div>';
}
