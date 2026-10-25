<?php
/**
 * KOP Tools > SEO Descriptions: a drafted description and focus keyphrase for every published post and
 * page whose Yoast fields are empty (docs/plan/4.7 items 9-10). Yoast's dashboard and editor check read
 * _yoast_wpseo_metadesc and _yoast_wpseo_focuskw, not the descriptions inc/seo.php prints, so these
 * pages warn there however good the live page is. The owner reads each draft, edits it in place and
 * approves it; only then is anything stored. Every approval is logged with the fields as they were and
 * has an exact Undo.
 *
 *   drafts       seeds/seo-descriptions.json (written by hand, by post id) first; then a rule per kind of
 *                page: state hubs by their number of programs, state report pages, country pages,
 *                document folders, lawsuit posts, news posts, everything else by its own opening words
 *                (kop_seo_post_description(), what the live page already prints)
 *   keyphrases   one rule per kind of page (kop_seo_drafts_keyphrase()). Facility and state wording waits
 *                on the owner (docs/plan/2.4 item 9): those boxes stay empty until the wording is set in
 *                this screen's "Keyphrase wording" box (option kop_seo_kw_wording)
 *   stored       option kop_seo_drafts_log (newest first, 500 kept) and kop_seo_drafts_skipped
 *
 * Generated /facility/ and /operator/ routes have no post and are not listed; inc/seo.php writes theirs.
 * Tested by scripts/test-seo-drafts.php against tmp/prod.sqlite.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_SEO_DRAFTS_LOG_MAX')) define('KOP_SEO_DRAFTS_LOG_MAX', 500);

add_filter('kop_admin_kit_pages', function ($pages) {
    $pages[] = 'kop-seo-drafts';
    return $pages;
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'SEO Descriptions', 'SEO Descriptions', 'manage_options', 'kop-seo-drafts', 'kop_seo_drafts_page');
}, 30);

add_action('admin_post_kop_seo_drafts', 'kop_seo_drafts_handle');

// ---------------------------------------------------------------------------
// Drafts
// ---------------------------------------------------------------------------

/** seeds/seo-descriptions.json's posts: id => {title, desc, kw}. */
function kop_seo_drafts_seed() {
    static $seed = null;
    if ($seed !== null) return $seed;
    $path = get_stylesheet_directory() . '/seeds/seo-descriptions.json';
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $seed = is_array($data['posts'] ?? null) ? $data['posts'] : array();
    return $seed;
}

/**
 * The keyphrase wording the owner has set: ['facility' => pattern, 'state' => pattern], '' = not set yet.
 * {name} is the program's name, {state} the state's.
 */
function kop_seo_drafts_wording() {
    $w = get_option('kop_seo_kw_wording', array());
    $w = is_array($w) ? $w : array();
    return array('facility' => trim((string) ($w['facility'] ?? '')), 'state' => trim((string) ($w['state'] ?? '')));
}

/** The page's template file ('templates/page-hub.php'), '' for none. */
function kop_seo_drafts_template($post) {
    $t = (string) get_post_meta((int) $post->ID, '_wp_page_template', true);
    return $t === 'default' ? '' : $t;
}

/** The post's title as plain text. */
function kop_seo_drafts_title($post) {
    return trim(html_entity_decode(wp_strip_all_tags((string) $post->post_title), ENT_QUOTES, 'UTF-8'));
}

/**
 * What kind of page this is, for the drafting rules and the card's label: state, state_reports, country,
 * facility, person, hub, article, folder, lawsuit, news, spotlight, post, page.
 */
function kop_seo_drafts_kind($post, $tpl = null) {
    $tpl = $tpl === null ? kop_seo_drafts_template($post) : $tpl;
    $by_tpl = array(
        'templates/page-state.php' => 'state', 'templates/page-state-reports.php' => 'state_reports',
        'templates/page-country.php' => 'country', 'templates/single-facility-profile.php' => 'facility',
        'templates/single-person-profile.php' => 'person', 'templates/page-hub.php' => 'hub',
        'templates/page-article.php' => 'article', 'templates/page-document-folder.php' => 'folder',
    );
    if (isset($by_tpl[$tpl])) return $by_tpl[$tpl];
    if ($post->post_type !== 'post') return 'page';
    $lines = kop_seo_post_lines($post);
    if (kop_seo_lawsuit_sentence($lines) !== '') return 'lawsuit';
    if (stripos(kop_seo_drafts_title($post), 'Investigatory Spotlight:') === 0) return 'spotlight';
    foreach (array_slice($lines, 0, 6) as $l) if (preg_match('/^(author|summary)\s*:/i', $l)) return 'news';
    return 'post';
}

/** Labels for the kinds. */
function kop_seo_drafts_kinds() {
    return array(
        'state' => 'State hub', 'state_reports' => 'State inspection reports', 'country' => 'Country page',
        'facility' => 'Facility profile', 'person' => 'Person', 'hub' => 'Hub page', 'article' => 'Article',
        'folder' => 'Document folder', 'lawsuit' => 'Lawsuit post', 'news' => 'News post',
        'spotlight' => 'Investigatory Spotlight', 'post' => 'Post', 'page' => 'Page',
    );
}

/** A state report page's state: "ut-reports" -> ['UT', 'Utah'], or null. */
function kop_seo_drafts_report_state($post) {
    if (!preg_match('/^([a-z]{2})-reports$/', (string) $post->post_name, $m)) return null;
    $code = strtoupper($m[1]);
    $names = function_exists('kop_facility_states') ? kop_facility_states() : array();
    $name = isset($names[$code]) ? ucwords(strtolower((string) $names[$code])) : '';
    return $name !== '' ? array($code, $name) : null;
}

/** Inspection reports held for one state, cached 12 hours. */
function kop_seo_drafts_report_count($code) {
    global $wpdb;
    $all = get_transient('kop_seo_report_counts');
    if (!is_array($all)) {
        $all = array();
        foreach ((array) $wpdb->get_results('SELECT f.state AS state, COUNT(*) AS n FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id GROUP BY f.state', ARRAY_A) as $r) {
            $all[strtoupper((string) $r['state'])] = (int) $r['n'];
        }
        set_transient('kop_seo_report_counts', $all, 12 * HOUR_IN_SECONDS);
    }
    return (int) ($all[strtoupper((string) $code)] ?? 0);
}

/** Facility records in one country (as facilities_v2 names it), cached 12 hours. */
function kop_seo_drafts_country_count($country) {
    global $wpdb;
    $all = get_transient('kop_seo_country_counts');
    if (!is_array($all)) {
        $all = array();
        foreach ((array) $wpdb->get_results('SELECT country, COUNT(*) AS n FROM facilities_v2 GROUP BY country', ARRAY_A) as $r) {
            $all[strtolower(trim((string) $r['country']))] = (int) $r['n'];
        }
        set_transient('kop_seo_country_counts', $all, 12 * HOUR_IN_SECONDS);
    }
    return (int) ($all[strtolower(trim((string) $country))] ?? 0);
}

/**
 * The program a news post is about, from the news_facility_links of its news_submissions row (found by the
 * import's json_data.source_post_id, else by an article link in the post): [name, other linked names].
 * One program named in the post's title wins; else the only program in the strongest link kind
 * (primary, then mentioned, then related); else ''. The other linked names come back for the card, for the owner to check.
 */
function kop_seo_drafts_news_program($post) {
    static $memo = array();
    $id = (int) $post->ID;
    if (isset($memo[$id])) return $memo[$id];
    $memo[$id] = array('', array());
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) return $memo[$id];
    try {
        $st = $pdo->prepare("SELECT id FROM news_submissions WHERE status IN ('approved','published') AND (json_data LIKE ? OR json_data LIKE ?) ORDER BY id LIMIT 1");
        $st->execute(array('%"source_post_id":' . $id . ',%', '%"source_post_id":' . $id . '}%'));
        $news = (int) $st->fetchColumn();
        if (!$news && preg_match_all('/href=["\'](https?:\/\/[^"\']+)["\']/i', (string) $post->post_content, $m)) {
            $urls = array();
            foreach (array_unique($m[1]) as $u) {
                $u = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
                if (stripos($u, 'kidsoverprofits.org') !== false) continue;
                $urls[] = $u;
                $urls[] = substr($u, -1) === '/' ? rtrim($u, '/') : $u . '/';
            }
            if ($urls = array_slice($urls, 0, 40)) {
                $st = $pdo->prepare("SELECT id FROM news_submissions WHERE status IN ('approved','published') AND article_url IN (" . implode(',', array_fill(0, count($urls), '?')) . ') ORDER BY id LIMIT 1');
                $st->execute($urls);
                $news = (int) $st->fetchColumn();
            }
        }
        if (!$news) return $memo[$id];
        $st = $pdo->prepare('SELECT f.name, l.link_type FROM news_facility_links l JOIN facilities_v2 f ON f.id = l.facility_id WHERE l.news_id = ?');
        $st->execute(array($news));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $memo[$id];
    }
    $rank = array('primary' => 0, 'mentioned' => 1, 'related' => 2);
    $names = array();   // name => strongest link kind
    foreach ($rows as $r) {
        $n = trim((string) preg_replace('/,?\s+(LLC|L\.L\.C\.|Inc\.?|Corp\.?)$/i', '', trim((string) $r['name'])));
        if ($n === '') continue;
        $k = $rank[(string) $r['link_type']] ?? 2;
        $names[$n] = isset($names[$n]) ? min($names[$n], $k) : $k;
    }
    if (!$names) return $memo[$id];
    $title = mb_strtolower(kop_seo_drafts_title($post));
    $in_title = array_values(array_filter(array_keys($names), function ($n) use ($title) { return mb_strpos($title, mb_strtolower($n)) !== false; }));
    $best = array_keys($names, min($names), true);
    asort($names);
    if (count($in_title) === 1) $pick = $in_title[0];
    elseif (count($best) === 1) $pick = $best[0];
    else $pick = '';
    return $memo[$id] = array($pick, array_values(array_diff(array_keys($names), array($pick))));
}

/** [description, how it was drafted] for one post. */
function kop_seo_drafts_description($post) {
    $seed = kop_seo_drafts_seed();
    if (!empty($seed[(string) $post->ID]['desc'])) return array((string) $seed[(string) $post->ID]['desc'], 'Written for this page');
    $title = kop_seo_drafts_title($post);
    switch (kop_seo_drafts_kind($post)) {
        case 'state':
            return array(kop_seo_state_description($title), 'State hub: its number of programs');
        case 'state_reports':
            $st = kop_seo_drafts_report_state($post);
            if ($st) {
                $n = kop_seo_drafts_report_count($st[0]);
                $agency = trim((string) preg_replace('/\s*inspections?\s+reports?$/i', '', $title));
                return array(kop_seo_clip(($n > 0 ? number_format($n) . ' ' : '') . $agency . ' inspection reports on youth residential programs in ' . $st[1]
                    . ', by facility, with the violations the state found.'), 'State report page: its number of reports');
            }
            break;
        case 'country':
            $n = kop_seo_drafts_country_count($title);
            if ($n > 1) return array(kop_seo_clip(number_format($n) . ' troubled teen programs on record in ' . $title . ', with their owners, years and status, lawsuits and news.'), 'Country page: its number of programs');
            return array(kop_seo_clip('The troubled teen program' . ($n === 1 ? '' : 's') . ' on record in ' . $title . ', with ' . ($n === 1 ? 'its owner, years and status' : 'their owners, years and status') . ', lawsuits and news.'), 'Country page');
        case 'folder':
            $subject = trim((string) preg_replace('/^document library:\s*/i', '', $title));
            return array(kop_seo_clip('The documents Kids Over Profits holds on ' . $subject . ', each one to read online or download.'), 'Document folder: what it holds');
    }
    $d = kop_seo_post_description($post);
    return array($d, $d === '' ? 'No text to draft from: write one' : 'Its own opening words (what the live page prints now)');
}

/**
 * [keyphrase, how it was drafted, waits] for one post. People (owner, 2026-10-20): the person's name and
 * their role or employer. Facility and state pages wait on the owner's wording (docs/plan/2.4 item 9).
 */
function kop_seo_drafts_keyphrase($post) {
    $seed = kop_seo_drafts_seed();
    $id = (string) $post->ID;
    if (isset($seed[$id]) && array_key_exists('kw', $seed[$id]) && (string) $seed[$id]['kw'] !== '') return array((string) $seed[$id]['kw'], 'Written for this page', false);
    $title = kop_seo_drafts_title($post);
    $w = kop_seo_drafts_wording();
    $fill = function ($pattern, $name) { return trim(str_replace(array('{name}', '{state}'), $name, $pattern)); };
    switch (kop_seo_drafts_kind($post)) {
        case 'state':
            return $w['state'] !== '' ? array($fill($w['state'], $title), 'State wording', false) : array('', 'Waits on the state wording below', true);
        case 'state_reports':
            $st = kop_seo_drafts_report_state($post);
            return array($st ? $st[1] . ' inspection reports' : '', 'State report page: the state + "inspection reports"', false);
        case 'country':
            return $w['state'] !== '' ? array($fill($w['state'], $title), 'State wording, for a country', false) : array('', 'Waits on the state wording below', true);
        case 'facility':
            return $w['facility'] !== '' ? array($fill($w['facility'], $title), 'Facility wording', false) : array('', 'Waits on the facility wording below', true);
        case 'person':
            return array($title, 'Person: their name; add their role or employer', false);
        case 'lawsuit':
            return array(trim((string) preg_replace('/,?\s*et\.? al\.?$/i', '', $title)), 'Lawsuit post: the case name', false);
        case 'spotlight':
            $subject = trim((string) preg_replace('/^investigatory spotlight:\s*/i', '', $title));
            return array(str_replace(' & ', ' ', $subject), 'Spotlight: who or what it is about', false);
        case 'folder':
            return array(trim((string) preg_replace('/^document library:\s*/i', '', $title)) . ' documents', 'Document folder: its subject + "documents"', false);
        case 'news':
            if ($w['facility'] === '') return array('', 'Waits on the facility wording below (the program it is about)', true);
            list($name, $others) = kop_seo_drafts_news_program($post);
            if ($name !== '') return array($fill($w['facility'], $name), 'News post: the program its story is linked to, in the facility wording' . ($others ? '; also linked: ' . implode(', ', array_slice($others, 0, 4)) : ''), false);
            return array('', $others ? 'News post linked to ' . implode(', ', array_slice($others, 0, 4)) . ': type the one it is about' : 'News post: type the program or company it is about', false);
    }
    return array('', 'No rule fits: type one, or leave it empty', false);
}

// ---------------------------------------------------------------------------
// Which pages
// ---------------------------------------------------------------------------

/**
 * Published posts and pages with an empty Yoast description or keyphrase that are not kept out of search
 * (Yoast's noindex box, or a tool page: kop_seo_noindex_page()) or redirected away, each [post, stored desc, stored kw]. Pages the owner skipped are left out unless $skipped.
 */
function kop_seo_drafts_candidates($skipped = false) {
    global $wpdb;
    $p = $wpdb->posts;
    $m = $wpdb->postmeta;
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_type, p.post_name, p.post_title, p.post_excerpt, p.post_content,
                d.meta_value AS metadesc, k.meta_value AS focuskw
           FROM $p p
           LEFT JOIN $m d ON d.post_id = p.ID AND d.meta_key = '_yoast_wpseo_metadesc'
           LEFT JOIN $m k ON k.post_id = p.ID AND k.meta_key = '_yoast_wpseo_focuskw'
           LEFT JOIN $m n ON n.post_id = p.ID AND n.meta_key = '_yoast_wpseo_meta-robots-noindex' AND n.meta_value = '1'
          WHERE p.post_status = 'publish' AND p.post_type IN ('post', 'page') AND n.post_id IS NULL
            AND (COALESCE(d.meta_value, '') = '' OR COALESCE(k.meta_value, '') = '')
          ORDER BY p.post_type DESC, p.post_title"
    );
    $skip = kop_seo_drafts_skipped();
    $out = array();
    $seen = array();
    foreach ((array) $rows as $r) {
        $id = (int) $r->ID;
        if (isset($seen[$id])) continue;   // a page with two meta rows of one key
        $seen[$id] = true;
        if (kop_seo_noindex_page($r, kop_seo_drafts_template($r))) continue;
        if (function_exists('kop_redirected_post_ids') && in_array($id, kop_redirected_post_ids(), true)) continue;   // redirects away (inc/redirects.php)
        if (isset($skip[$id]) !== (bool) $skipped) continue;
        $out[] = array($r, trim((string) $r->metadesc), trim((string) $r->focuskw));
    }
    return $out;
}

/** id => time skipped. */
function kop_seo_drafts_skipped() {
    $s = get_option('kop_seo_drafts_skipped', array());
    return is_array($s) ? $s : array();
}

/** Newest first. */
function kop_seo_drafts_log() {
    $l = get_option('kop_seo_drafts_log', array());
    return is_array($l) ? $l : array();
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

/** Yoast's stored copy of a post (its indexable) rebuilt after its fields change, when Yoast is there. */
function kop_seo_drafts_refresh_yoast($id) {
    if (!function_exists('YoastSEO')) return;
    try {
        $repo = YoastSEO()->classes->get('Yoast\WP\SEO\Repositories\Indexable_Repository');
        $builder = YoastSEO()->classes->get('Yoast\WP\SEO\Builders\Indexable_Builder');
        $indexable = $repo->find_by_id_and_type((int) $id, 'post', false);
        if ($indexable) $builder->build($indexable);
    } catch (Throwable $e) {
        // The meta is stored either way; Yoast rebuilds the indexable on the post's next save.
    }
}

/** Set one Yoast field: '' deletes it. */
function kop_seo_drafts_set($id, $key, $value) {
    $value = (string) $value;
    if ($value === '') delete_post_meta((int) $id, $key);
    else update_post_meta((int) $id, $key, wp_slash($value));
}

/**
 * Store an approved description and keyphrase on post $id, logged with the fields as they were.
 * An empty box leaves that field as it is. Returns the log entry, or null when nothing changed.
 */
function kop_seo_drafts_approve($id, $desc, $kw, $via = '') {
    $id = (int) $id;
    $desc = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $desc)));
    $kw = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $kw)));
    $before = array(
        'metadesc' => (string) get_post_meta($id, '_yoast_wpseo_metadesc', true),
        'focuskw'  => (string) get_post_meta($id, '_yoast_wpseo_focuskw', true),
    );
    $after = $before;
    if ($desc !== '') $after['metadesc'] = $desc;
    if ($kw !== '') $after['focuskw'] = $kw;
    if ($after === $before) return null;
    kop_seo_drafts_set($id, '_yoast_wpseo_metadesc', $after['metadesc']);
    kop_seo_drafts_set($id, '_yoast_wpseo_focuskw', $after['focuskw']);
    kop_seo_drafts_refresh_yoast($id);
    $entry = array('token' => function_exists('wp_generate_password') ? wp_generate_password(12, false) : bin2hex(random_bytes(6)),
        'post' => $id, 'before' => $before, 'after' => $after, 'user' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        'at' => time(), 'undone' => 0, 'via' => (string) $via);
    $log = kop_seo_drafts_log();
    array_unshift($log, $entry);
    update_option('kop_seo_drafts_log', array_slice($log, 0, KOP_SEO_DRAFTS_LOG_MAX), false);
    $skip = kop_seo_drafts_skipped();
    if (isset($skip[$id])) { unset($skip[$id]); update_option('kop_seo_drafts_skipped', $skip, false); }
    return $entry;
}

/**
 * Store every draft a rule made (owner, 2026-10-20: "store the rule drafts, with Undo"): each listed page not in
 * seeds/seo-descriptions.json gets its rule description and, where a rule gives one, its keyphrase, logged like an
 * approval (Done tab, Undo). Hand-written pages, pages with nothing drafted, skipped pages and a person's keyphrase
 * (name only; the owner adds role or employer) stay for the owner. Runs once on deploy and again when the keyphrase
 * wording is saved, so the facility, state and news keyphrases follow. Returns how many pages were stored.
 */
function kop_seo_drafts_store_rule_drafts() {
    $seed = kop_seo_drafts_seed();
    $done = 0;
    foreach (kop_seo_drafts_candidates(false) as $c) {
        $post = $c[0];
        if (isset($seed[(string) $post->ID])) continue;
        $desc = $c[1] !== '' ? '' : kop_seo_drafts_description($post)[0];
        $kw = '';
        if ($c[2] === '' && kop_seo_drafts_kind($post) !== 'person') {
            $k = kop_seo_drafts_keyphrase($post);
            if (empty($k[2])) $kw = (string) $k[0];
        }
        if (kop_seo_drafts_approve((int) $post->ID, $desc, $kw, 'rules')) $done++;
    }
    return $done;
}

/** The first store, once per version (bump to run it again after a rule changes). */
function kop_seo_drafts_maybe_store_rule_drafts() {
    $version = 1;
    if (!current_user_can('manage_options') || (int) get_option('kop_seo_drafts_rules_v', 0) >= $version) return;
    update_option('kop_seo_drafts_rules_v', $version, false);
    kop_seo_drafts_store_rule_drafts();
}
add_action('admin_init', 'kop_seo_drafts_maybe_store_rule_drafts');

/**
 * Put back the fields an approval replaced. Refused (false) when either field has changed since, so a
 * later edit in the post editor is never overwritten.
 */
function kop_seo_drafts_undo($token) {
    $log = kop_seo_drafts_log();
    foreach ($log as $i => $e) {
        if (($e['token'] ?? '') !== (string) $token || !empty($e['undone'])) continue;
        $id = (int) $e['post'];
        $now = array(
            'metadesc' => (string) get_post_meta($id, '_yoast_wpseo_metadesc', true),
            'focuskw'  => (string) get_post_meta($id, '_yoast_wpseo_focuskw', true),
        );
        if ($now !== $e['after']) return false;
        kop_seo_drafts_set($id, '_yoast_wpseo_metadesc', $e['before']['metadesc']);
        kop_seo_drafts_set($id, '_yoast_wpseo_focuskw', $e['before']['focuskw']);
        kop_seo_drafts_refresh_yoast($id);
        $log[$i]['undone'] = time();
        update_option('kop_seo_drafts_log', $log, false);
        return true;
    }
    return false;
}

function kop_seo_drafts_handle() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    check_admin_referer('kop_seo_drafts');
    $back = wp_get_referer() ?: admin_url('admin.php?page=kop-seo-drafts');
    $back = remove_query_arg(array('kop_done', 'kop_undo_failed'), $back);
    $drafts = (array) wp_unslash($_POST['drafts'] ?? array());
    $done = 0;

    if (isset($_POST['wording'])) {
        $w = (array) wp_unslash($_POST['wording']);
        update_option('kop_seo_kw_wording', array(
            'facility' => sanitize_text_field((string) ($w['facility'] ?? '')),
            'state'    => sanitize_text_field((string) ($w['state'] ?? '')),
        ), false);
        $n = kop_seo_drafts_store_rule_drafts();   // the keyphrases that waited on the wording
        wp_safe_redirect(add_query_arg('kop_done', 'wording:' . $n, $back));
        exit;
    }
    if (!empty($_POST['undo'])) {
        $ok = kop_seo_drafts_undo(sanitize_text_field(wp_unslash($_POST['undo'])));
        wp_safe_redirect(add_query_arg($ok ? 'kop_done' : 'kop_undo_failed', $ok ? 'undo' : '1', $back));
        exit;
    }
    $ids = array();
    if (!empty($_POST['one'])) $ids = array((int) $_POST['one']);
    elseif (!empty($_POST['skip_one'])) $ids = array((int) $_POST['skip_one']);
    elseif (!empty($_POST['unskip_one'])) $ids = array((int) $_POST['unskip_one']);
    else $ids = array_map('intval', (array) ($_POST['pick'] ?? array()));
    $ids = array_values(array_filter($ids));

    $skip = kop_seo_drafts_skipped();
    if (!empty($_POST['skip_one']) || !empty($_POST['skip_ticked'])) {
        foreach ($ids as $id) { $skip[$id] = time(); $done++; }
        update_option('kop_seo_drafts_skipped', $skip, false);
        wp_safe_redirect(add_query_arg('kop_done', 'skip:' . $done, $back));
        exit;
    }
    if (!empty($_POST['unskip_one'])) {
        foreach ($ids as $id) { unset($skip[$id]); $done++; }
        update_option('kop_seo_drafts_skipped', $skip, false);
        wp_safe_redirect(add_query_arg('kop_done', 'unskip:' . $done, $back));
        exit;
    }
    foreach ($ids as $id) {
        $d = (array) ($drafts[$id] ?? array());
        if (kop_seo_drafts_approve($id, (string) ($d['desc'] ?? ''), (string) ($d['kw'] ?? ''))) $done++;
    }
    wp_safe_redirect(add_query_arg('kop_done', 'approve:' . $done, $back));
    exit;
}

// ---------------------------------------------------------------------------
// Screen
// ---------------------------------------------------------------------------

/** A button that submits the screen's form (kop_ak_button() is type="button", for scripted screens). */
function kop_seo_drafts_submit($label, $variant, $attrs, $form = '') {
    return '<button type="submit" class="button kop-ak-btn kop-ak-btn--' . $variant . '" ' . trim($attrs) . ($form !== '' ? ' form="' . esc_attr($form) . '"' : '') . '>' . esc_html($label) . '</button>';
}

/** One page's card: what it is, what is stored, the drafts in editable boxes and its buttons. */
function kop_seo_drafts_card(array $c, $skipped = false, array $twins = array()) {
    list($post, $stored_desc, $stored_kw) = $c;
    $id = (int) $post->ID;
    $kind = kop_seo_drafts_kind($post);
    $kinds = kop_seo_drafts_kinds();
    list($desc, $desc_how) = $stored_desc !== '' ? array($stored_desc, 'Already stored') : kop_seo_drafts_description($post);
    list($kw, $kw_how, $waits) = $stored_kw !== '' ? array($stored_kw, 'Already stored', false) : kop_seo_drafts_keyphrase($post);
    $url = get_permalink($post);
    $title = kop_seo_drafts_title($post);
    $len = mb_strlen($desc);

    $out = '<article class="kop-seod-card" data-kop-seod-card>';
    // The tick box names the post; ticked cards are approved or skipped together.
    $out .= kop_ak_card_head(
        ($skipped ? '' : '<input type="checkbox" class="kop-seod-pick" name="pick[]" value="' . $id . '" aria-label="Tick ' . esc_attr($title) . '"> ')
            . '<a class="kop-seod-title" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($title) . '</a>',
        '<span class="kop-seod-kind">' . esc_html($kinds[$kind] ?? $kind) . '</span> '
            . '<a class="kop-seod-edit" href="' . esc_url(admin_url('post.php?post=' . $id . '&action=edit')) . '">Edit post</a>',
        false
    );
    $out .= '<div class="kop-seod-fields">'
        . '<label class="kop-seod-label" for="kop-seod-d-' . $id . '">Description <span class="kop-seod-count" data-kop-seod-count>' . $len . ' of 158</span></label>'
        . '<textarea id="kop-seod-d-' . $id . '" name="drafts[' . $id . '][desc]" rows="3" maxlength="300" data-kop-seod-desc' . ($stored_desc !== '' ? ' readonly' : '') . '>' . esc_textarea($desc) . '</textarea>'
        . '<p class="kop-seod-how">' . esc_html($desc_how) . '</p>'
        . ($twins ? '<p class="kop-seod-how is-waiting">Same draft as ' . implode(', ', array_map(function ($t) {
            return '<a href="' . esc_url(get_permalink($t)) . '" target="_blank" rel="noopener">' . esc_html(kop_seo_drafts_title($t)) . '</a>';
        }, $twins)) . ': the same text posted twice? Skip one, or write a different description.</p>' : '')
        . '<label class="kop-seod-label" for="kop-seod-k-' . $id . '">Focus keyphrase</label>'
        . '<input type="text" id="kop-seod-k-' . $id . '" name="drafts[' . $id . '][kw]" value="' . esc_attr($kw) . '"' . ($stored_kw !== '' ? ' readonly' : '') . '>'
        . '<p class="kop-seod-how' . ($waits ? ' is-waiting' : '') . '">' . esc_html($kw_how) . '</p>'
        . '</div>';
    $buttons = $skipped
        ? kop_seo_drafts_submit('Put back in the list', 'secondary', 'name="unskip_one" value="' . $id . '"')
        : kop_seo_drafts_submit('Approve', 'primary', 'name="one" value="' . $id . '"')
            . kop_seo_drafts_submit('Skip', 'secondary', 'name="skip_one" value="' . $id . '"');
    $out .= kop_ak_card_actions($buttons);
    return $out . '</article>';
}

/** The Done tab: recent approvals, each with Undo while it is the latest word. */
function kop_seo_drafts_done_list() {
    $log = kop_seo_drafts_log();
    if (!$log) return kop_ak_empty('Nothing approved yet.');
    $out = '<div class="kop-seod-list">';
    foreach ($log as $e) {
        $id = (int) $e['post'];
        $post = get_post($id);
        $name = $post ? kop_seo_drafts_title($post) : 'Post ' . $id;
        $out .= '<article class="kop-seod-card' . (!empty($e['undone']) ? ' is-undone' : '') . '">'
            . '<p class="kop-seod-done-head"><a class="kop-seod-title" href="' . esc_url(get_permalink($post ?: $id)) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a> '
            . '<span class="kop-seod-kind">' . esc_html(wp_date('M j, Y g:i a', (int) $e['at'])) . (($e['via'] ?? '') === 'rules' ? ', stored by rule' : '') . (!empty($e['undone']) ? ', undone' : '') . '</span></p>'
            . '<dl class="kop-seod-diff">';
        foreach (array('metadesc' => 'Description', 'focuskw' => 'Focus keyphrase') as $k => $label) {
            if ($e['before'][$k] === $e['after'][$k]) continue;
            $out .= '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($e['after'][$k])
                . ($e['before'][$k] !== '' ? '<br><span class="kop-seod-was">was: ' . esc_html($e['before'][$k]) . '</span>' : '') . '</dd>';
        }
        $out .= '</dl>';
        if (empty($e['undone'])) $out .= kop_ak_card_actions(kop_seo_drafts_submit('Undo', 'secondary', 'name="undo" value="' . esc_attr($e['token']) . '"'));
        $out .= '</article>';
    }
    return $out . '</div>';
}

function kop_seo_drafts_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    kop_seo_drafts_screen();
}

/** The screen itself (kop_seo_drafts_page() checks who is asking first). */
function kop_seo_drafts_screen() {
    $tab = sanitize_key($_GET['tab'] ?? '');
    $tab = in_array($tab, array('skipped', 'done'), true) ? $tab : '';
    $kind = sanitize_key($_GET['kind'] ?? '');
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $per = 40;
    $base = admin_url('admin.php?page=kop-seo-drafts');

    echo '<div class="wrap kop-ak kop-seod">';
    echo kop_ak_header('SEO Descriptions', array(
        'lede' => 'A drafted search description and focus keyphrase for every post and page whose Yoast fields are empty, so Yoast stops warning about them. Nothing is stored until you approve it.',
        'steps' => array(
            'Read the draft. Change any words in the box; the count shows how much of it Google prints (158 characters).',
            '<em>Approve</em> stores both boxes in the post\'s Yoast fields. An empty box leaves that field alone. Tick several cards to approve them together.',
            '<em>Skip</em> takes a page off the list without storing anything. <em>Done</em> lists every approval with Undo.',
        ),
        'help' => '<p>Yoast\'s dashboard and its check in the editor read the description and keyphrase stored in the post, not the description the site prints for it, so a page can be fine in Google and still warn in Yoast. Once stored, the description here is also the one the live page prints.</p>'
            . '<p>Drafts: some were written for the page; the rest come from a rule for their kind of page (shown under each box). Facility and state keyphrases wait on the wording you choose below (plan 2.4 item 9).</p>'
            . '<p>Facility and company pages built from the records have no post and are not listed: their descriptions come from the record.</p>',
    ));

    $flash = sanitize_text_field(wp_unslash($_GET['kop_done'] ?? ''));
    if ($flash !== '') {
        list($what, $n) = array_pad(explode(':', $flash, 2), 2, '');
        $msg = array('approve' => (int) $n . ' approved.', 'skip' => (int) $n . ' skipped.', 'unskip' => 'Put back in the list.', 'undo' => 'Undone: the fields are as they were.', 'wording' => 'Keyphrase wording saved; ' . (int) $n . ' pages took their keyphrase from it (Done tab, Undo).');
        echo kop_ak_status(esc_html($msg[$what] ?? 'Saved.'));
    }
    if (!empty($_GET['kop_undo_failed'])) echo kop_ak_status('Not undone: the fields have changed since (in the post editor or another approval). Change them in the editor instead.');

    $todo = kop_seo_drafts_candidates(false);
    $skipped = kop_seo_drafts_candidates(true);
    $tabs = array(
        ''        => array('label' => 'To review', 'count' => count($todo)),
        'skipped' => array('label' => 'Skipped', 'count' => count($skipped)),
        'done'    => array('label' => 'Done', 'count' => count(array_filter(kop_seo_drafts_log(), function ($e) { return empty($e['undone']); }))),
    );
    echo kop_ak_tabs($tabs, $tab, function ($t) { return admin_url('admin.php?' . http_build_query(array_filter(array('page' => 'kop-seo-drafts', 'tab' => $t)))); });

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('kop_seo_drafts');
    echo '<input type="hidden" name="action" value="kop_seo_drafts">';

    if ($tab === 'done') {
        echo kop_seo_drafts_done_list() . '</form></div>';
        return;
    }

    if ($tab === '') {
        $w = kop_seo_drafts_wording();
        echo kop_ak_fold('Keyphrase wording for facility and state pages' . ($w['facility'] === '' || $w['state'] === '' ? ' (not set yet)' : ''),
            '<p>Which words people search for (plan 2.4 item 9). <code>{name}</code> is the program\'s or state\'s name. Leave a box empty to keep those keyphrases waiting.</p>'
            . '<p><label>Facility pages and the posts about one program <input type="text" form="kop-seod-wording" name="wording[facility]" value="' . esc_attr($w['facility']) . '" placeholder="{name}, or {name} abuse"></label></p>'
            . '<p><label>State hubs and country pages <input type="text" form="kop-seod-wording" name="wording[state]" value="' . esc_attr($w['state']) . '" placeholder="troubled teen programs {name}"></label></p>'
            . '<p>' . kop_seo_drafts_submit('Save wording', 'secondary', 'form="kop-seod-wording"') . '</p>',
            false, 'kop-seod-wording');
    }

    $list = $tab === 'skipped' ? $skipped : $todo;
    $counts = array();
    foreach ($list as $c) { $k = kop_seo_drafts_kind($c[0]); $counts[$k] = ($counts[$k] ?? 0) + 1; }
    if ($counts) {
        $kinds = kop_seo_drafts_kinds();
        echo '<ul class="kop-seod-kinds">';
        echo '<li' . ($kind === '' ? ' class="is-current"' : '') . '><a href="' . esc_url(add_query_arg('tab', $tab, $base)) . '">All <span class="kop-ak-count">' . count($list) . '</span></a></li>';
        foreach ($counts as $k => $n) {
            echo '<li' . ($kind === $k ? ' class="is-current"' : '') . '><a href="' . esc_url(admin_url('admin.php?' . http_build_query(array_filter(array('page' => 'kop-seo-drafts', 'tab' => $tab, 'kind' => $k))))) . '">'
                . esc_html($kinds[$k] ?? $k) . ' <span class="kop-ak-count">' . (int) $n . '</span></a></li>';
        }
        echo '</ul>';
    }
    if ($kind !== '') $list = array_values(array_filter($list, function ($c) use ($kind) { return kop_seo_drafts_kind($c[0]) === $kind; }));

    $pages = (int) ceil(count($list) / $per);
    $paged = min($paged, max(1, $pages));
    $pager = kop_ak_pager($paged, $pages, count($list), 'pages', function ($n) use ($tab, $kind) {
        return admin_url('admin.php?' . http_build_query(array_filter(array('page' => 'kop-seo-drafts', 'tab' => $tab, 'kind' => $kind, 'paged' => $n > 1 ? $n : ''))));
    });
    if (!$list) {
        echo kop_ak_empty($tab === 'skipped' ? 'Nothing skipped.' : 'Every post and page has its Yoast description and keyphrase.');
    } else {
        if ($tab === '') echo kop_ak_bar(kop_seo_drafts_submit('Approve ticked', 'primary', 'name="approve_ticked" value="1"') . ' ' . kop_seo_drafts_submit('Skip ticked', 'secondary', 'name="skip_ticked" value="1"'));
        // Pages whose drafts come out the same, so each card can name the other.
        $by_draft = array();
        foreach ($list as $c) {
            $d = $c[1] !== '' ? $c[1] : kop_seo_drafts_description($c[0])[0];
            if ($d !== '') $by_draft[$d][] = $c[0];
        }
        echo $pager . '<div class="kop-seod-list">';
        foreach (array_slice($list, ($paged - 1) * $per, $per) as $c) {
            $d = $c[1] !== '' ? $c[1] : kop_seo_drafts_description($c[0])[0];
            $twins = array_values(array_filter($by_draft[$d] ?? array(), function ($p) use ($c) { return (int) $p->ID !== (int) $c[0]->ID; }));
            echo kop_seo_drafts_card($c, $tab === 'skipped', $twins);
        }
        echo '</div>' . $pager;
    }
    echo '</form>';
    // The wording boxes sit inside the fold above but post on their own.
    if ($tab === '') {
        echo '<form id="kop-seod-wording" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('kop_seo_drafts');
        echo '<input type="hidden" name="action" value="kop_seo_drafts"></form>';
    }
    ?>
    <style>
        .kop-seod-kinds { display:flex; flex-wrap:wrap; gap:6px; margin:10px 0; padding:0; list-style:none; max-width:1100px; }
        .kop-seod-kinds a { display:inline-block; padding:4px 10px; border:1px solid #c3c4c7; border-radius:3px; background:var(--kop-white, #fff); color:var(--kop-text-primary, #1d2327); text-decoration:none; }
        .kop-seod-kinds .is-current a { border-color:var(--kop-navy, #000080); box-shadow:inset 0 0 0 1px var(--kop-navy, #000080); }
        .kop-seod-list { display:grid; gap:10px; max-width:1100px; margin:10px 0; }
        .kop-seod-card { padding:10px 14px; background:var(--kop-white, #fff); border:1px solid #c3c4c7; border-radius:3px; }
        .kop-seod-card.is-undone { opacity:.75; }
        .kop-seod-title { font-weight:600; color:var(--kop-navy, #000080); overflow-wrap:anywhere; }
        .kop-seod-kind, .kop-seod-how, .kop-seod-was { color:var(--kop-text-muted, #4A5568); font-size:12px; }
        .kop-seod-edit { font-size:12px; color:var(--kop-navy, #000080); }
        .kop-seod-fields { display:grid; gap:4px; margin-top:8px; }
        .kop-seod-label { font-weight:600; color:var(--kop-text-primary, #1d2327); }
        .kop-seod-fields textarea, .kop-seod-fields input[type=text] { width:100%; max-width:100%; box-sizing:border-box; }
        .kop-seod-how { margin:0 0 6px; }
        .kop-seod-how.is-waiting { color:#8a4b00; }
        .kop-seod-count { font-weight:400; color:var(--kop-text-muted, #4A5568); font-size:12px; }
        .kop-seod-count.is-over { color:#8a1f1f; font-weight:600; }
        .kop-seod-diff { display:grid; grid-template-columns:max-content 1fr; gap:2px 12px; margin:6px 0 0; }
        .kop-seod-diff dt { font-weight:600; color:var(--kop-text-primary, #1d2327); }
        .kop-seod-diff dd { margin:0; color:var(--kop-text-primary, #1d2327); overflow-wrap:anywhere; }
        .kop-seod-done-head { margin:0; }
        .kop-seod-wording input[type=text] { width:100%; max-width:420px; display:block; margin-top:2px; }
        @media (max-width:600px) { .kop-seod-diff { grid-template-columns:1fr; } }
    </style>
    <script>
    (function () {
        document.querySelectorAll('[data-kop-seod-card]').forEach(function (card) {
            var box = card.querySelector('[data-kop-seod-desc]'), count = card.querySelector('[data-kop-seod-count]');
            if (!box || !count) return;
            var show = function () { var n = box.value.length; count.textContent = n + ' of 158'; count.classList.toggle('is-over', n > 158 || n < 70); };
            box.addEventListener('input', show);
            show();
        });
    })();
    </script>
    <?php
    echo '</div>';
}
