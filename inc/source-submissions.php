<?php
/**
 * "Send to Kids Over Profits" browser extension (browser-extension/send-to-kop/).
 *
 * The extension signs in with a WordPress application password and posts the
 * page it is on here. Each type lands in the queue that already reviews it:
 *
 *   article     -> news_submissions (status 'submitted', Submissions Review, News tab)
 *   lawsuit     -> lawsuits (publication_status 'pending', Lawsuit admin)
 *   legislation -> legislation (publication_status 'pending', Legislation admin)
 *   website     -> kop_source posts (pending, KOP Tools > Websites Sent In)
 *
 * Duplicates are caught with the same rules the public forms use
 * (api/url-dedupe.php), so nothing sent here can bypass them.
 *
 * Routes (edit_posts only):
 *   POST /wp-json/kop/v1/extension/submit
 *   GET  /wp-json/kop/v1/extension/check?url=...&title=...&site_name=...
 */

// Dropdown lists (kop_choices(); KOP Tools > Dropdown Lists)
require_once __DIR__ . '/choice-lists.php';


if (!defined('ABSPATH')) {
    exit;
}

const KOP_EXT_SOURCE_CPT = 'kop_source';

/** True for the extension's routes and the app's kop/v1/app/ routes. */
function kop_ext_is_own_route() {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    foreach (array('/kop/v1/extension/', '/kop/v1/app/') as $prefix) {
        if (strpos($uri, $prefix) !== false || strpos(rawurldecode($uri), $prefix) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Some CGI/LiteSpeed setups drop the Authorization header before PHP sees it,
 * which leaves application passwords with nothing to check. The extension
 * sends the same credentials in X-KOP-Authorization as well; copy them into
 * PHP_AUTH_* for its own routes (and the app's kop/v1/app/) only, before
 * WordPress authenticates.
 */
function kop_ext_restore_basic_auth() {
    if (!empty($_SERVER['PHP_AUTH_USER']) || empty($_SERVER['HTTP_X_KOP_AUTHORIZATION'])) {
        return;
    }
    if (!kop_ext_is_own_route()) {
        return;
    }
    $header = (string) $_SERVER['HTTP_X_KOP_AUTHORIZATION'];
    if (stripos($header, 'basic ') !== 0) {
        return;
    }
    $decoded = base64_decode(substr($header, 6), true);
    if ($decoded === false || strpos($decoded, ':') === false) {
        return;
    }
    list($user, $pass) = explode(':', $decoded, 2);
    $_SERVER['PHP_AUTH_USER'] = $user;
    $_SERVER['PHP_AUTH_PW']   = $pass;
}
add_filter('determine_current_user', function ($user_id) {
    kop_ext_restore_basic_auth();
    return $user_id;
}, 5);

/**
 * The application password comes before a login cookie on these routes. On Android the app's requests share
 * the admin web view's cookies, and WordPress checks the logged_in cookie first (priority 20), then drops the
 * user for having no nonce, so a valid application password got a 401.
 */
add_filter('determine_current_user', function ($user_id) {
    if ($user_id || empty($_SERVER['PHP_AUTH_USER']) || !kop_ext_is_own_route() || !function_exists('wp_validate_application_password')) {
        return $user_id;
    }
    return wp_validate_application_password($user_id);
}, 15);

/* ---------- Websites queue ---------- */

function kop_ext_register_source_type() {
    register_post_type(KOP_EXT_SOURCE_CPT, array(
        'labels' => array(
            'name'          => 'Websites Sent In',
            'singular_name' => 'Website',
            'menu_name'     => 'Websites Sent In',
            'all_items'     => 'Websites Sent In',
            'edit_item'     => 'Review website',
            'add_new_item'  => 'Add website',
            'not_found'     => 'No websites sent in yet.',
        ),
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => function_exists('kop_tools_parent_slug') ? kop_tools_parent_slug() : true,
        'capability_type' => 'post',
        'map_meta_cap' => true,
        'supports'     => array('title', 'editor'),
    ));
}
add_action('init', 'kop_ext_register_source_type');

/** Meta fields shown on a website entry, key => label. */
function kop_ext_source_fields() {
    return array(
        'url'         => 'Link',
        'kind'        => 'Kind of website',
        'site_name'   => 'Site',
        'author'      => 'Author or source',
        'published'   => 'Date',
        'facility'    => 'Related facility',
        'tags'        => 'Tags',
        'description' => 'Page description',
        'selection'   => 'Highlighted text',
        'submitted_by' => 'Sent by',
    );
}

/**
 * What kind of page a website sent in is: key => label (meta _kop_kind). The
 * kinds are in inc/choice-lists/sorting.php (KOP Tools > Dropdown Lists);
 * $mode 'offered' leaves out hidden ones (dropdowns).
 */
function kop_ext_website_kinds($mode = 'all') {
    return kop_choices('website-kinds', null, $mode);
}

/** A clean kind: one of kop_ext_website_kinds(), else ''. */
function kop_ext_website_kind($value) {
    $value = strtolower(trim((string) $value));
    return isset(kop_ext_website_kinds()[$value]) ? $value : '';
}

/** The kind the address says, when it says one ('' otherwise). */
function kop_ext_website_guess_kind($url) {
    $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    $host = preg_replace('/^(www|old|m)\./', '', $host);
    if ($host === '') return '';
    $is = function (array $hosts) use ($host) {
        foreach ($hosts as $h) {
            if ($host === $h || substr($host, -strlen($h) - 1) === '.' . $h) return true;
        }
        return false;
    };
    if ($is(array('reddit.com', 'redd.it'))) return 'reddit';
    if ($is(array('web.archive.org', 'archive.org', 'archive.today', 'archive.ph', 'archive.is'))) return 'archive';
    if ($is(array('fornits.com', 'fornits.org'))) return 'forum';
    if ($is(array('youtube.com', 'youtu.be', 'vimeo.com', 'podcasts.apple.com', 'open.spotify.com', 'soundcloud.com'))) return 'media';
    // A LinkedIn person page is a staff profile; the rest of LinkedIn stays social.
    if ($is(array('linkedin.com')) && preg_match('#^/(in|pub)/#i', (string) parse_url((string) $url, PHP_URL_PATH))) return 'staff';
    if ($is(array('zoominfo.com', 'rocketreach.co', 'signalhire.com', 'contactout.com', 'doximity.com', 'healthgrades.com', 'npiprofile.com', 'npino.com'))) return 'staff';
    if ($is(array('opencorporates.com', 'bizapedia.com', 'corporationwiki.com', 'bizprofile.net', 'buzzfile.com', 'dnb.com',
        'opengovus.com', 'zillow.com', 'redfin.com', 'loopnet.com', 'realtor.com'))) return 'business';
    if ($is(array('facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'tiktok.com', 'threads.net', 'bsky.app', 'linkedin.com'))) return 'social';
    if ($is(array('wordpress.com', 'blogspot.com', 'medium.com', 'substack.com', 'tumblr.com'))) return 'blog';
    if ($is(array('psychologytoday.com', 'yelp.com', 'glassdoor.com', 'indeed.com', 'bbb.org', 'findtreatment.gov', 'natsap.org'))) return 'directory';
    if (preg_match('/\.(gov|mil)$|\.state\.[a-z]{2}\.us$|\.gov\.[a-z]{2}$/', $host)) return 'government';
    return '';
}

add_filter('manage_' . KOP_EXT_SOURCE_CPT . '_posts_columns', function ($cols) {
    $out = array();
    foreach ($cols as $key => $label) {
        $out[$key] = $label;
        if ($key === 'title') {
            $out['kop_link']     = 'Link';
            $out['kop_kind']     = 'Kind';
            $out['kop_facility'] = 'Facility';
        }
    }
    return $out;
});

add_action('manage_' . KOP_EXT_SOURCE_CPT . '_posts_custom_column', function ($col, $id) {
    if ($col === 'kop_link') {
        $url = get_post_meta($id, '_kop_url', true);
        if ($url) {
            printf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($url), esc_html((string) wp_parse_url($url, PHP_URL_HOST)));
        }
    }
    if ($col === 'kop_kind') {
        $kind = kop_ext_website_kind(get_post_meta($id, '_kop_kind', true));
        echo esc_html($kind !== '' ? kop_ext_website_kinds()[$kind] : '');
    }
    if ($col === 'kop_facility') {
        echo esc_html((string) get_post_meta($id, '_kop_facility', true));
    }
}, 10, 2);

/**
 * Moving a website to the news, lawsuit or bill queue (or onto a facility) is
 * done on its Review inbox card; this screen links there.
 */
function kop_ext_website_inbox_url() {
    return function_exists('kop_submissions_review_url') ? (string) kop_submissions_review_url('websites') : '';
}

add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== KOP_EXT_SOURCE_CPT || $post->post_status === 'trash') return $actions;
    $url = kop_ext_website_inbox_url();
    if ($url !== '') {
        $actions = array('kop_inbox' => sprintf('<a href="%s">%s</a>', esc_url($url), 'Move to news, lawsuits, bills or a facility')) + $actions;
    }
    return $actions;
}, 10, 2);

add_action('admin_notices', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== KOP_EXT_SOURCE_CPT || !in_array($screen->base, array('edit', 'post'), true)) return;
    $url = kop_ext_website_inbox_url();
    if ($url === '') return;
    printf('<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
        esc_html('To send a website to the news, lawsuit or legislation queue, Industry PR or a facility record, use its card in the Review inbox.'),
        esc_url($url), esc_html('Open Websites sent in there'));
});

add_action('add_meta_boxes_' . KOP_EXT_SOURCE_CPT, function () {
    add_meta_box('kop_ext_source_details', 'Website details', 'kop_ext_render_source_box', KOP_EXT_SOURCE_CPT, 'normal', 'high');
});

function kop_ext_render_source_box($post) {
    wp_nonce_field('kop_ext_source_save', 'kop_ext_source_nonce');
    echo '<table class="form-table" role="presentation">';
    foreach (kop_ext_source_fields() as $key => $label) {
        $val = (string) get_post_meta($post->ID, '_kop_' . $key, true);
        $name = 'kop_ext_source[' . $key . ']';
        if ($key === 'kind') {
            $kind = kop_ext_website_kind($val);
            $opts = '<option value="">' . esc_html('Not said') . '</option>';
            foreach (kop_ext_website_kinds('offered') + ($kind !== '' ? array($kind => kop_ext_website_kinds()[$kind]) : array()) as $k => $l) {
                $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($k), selected($kind, $k, false), esc_html($l));
            }
            $field = sprintf('<select id="kop-ext-kind" name="%s"%s>%s</select>', esc_attr($name), kop_choice_select_attr('website-kinds'), $opts);
        } elseif (in_array($key, array('description', 'selection'), true)) {
            $field = sprintf('<textarea id="kop-ext-%1$s" name="%2$s" rows="3" class="large-text">%3$s</textarea>', esc_attr($key), esc_attr($name), esc_textarea($val));
        } else {
            $field = sprintf('<input type="text" id="kop-ext-%1$s" name="%2$s" value="%3$s" class="regular-text"%4$s>', esc_attr($key), esc_attr($name), esc_attr($val), $key === 'submitted_by' ? ' readonly' : '');
            if ($key === 'url' && $val !== '') {
                $field .= sprintf(' <a href="%s" target="_blank" rel="noopener noreferrer">Open</a>', esc_url($val));
            }
        }
        printf('<tr><th><label for="kop-ext-%s">%s</label></th><td>%s</td></tr>', esc_attr($key), esc_html($label), $field);
    }
    echo '</table>';
}

add_action('save_post_' . KOP_EXT_SOURCE_CPT, function ($id) {
    if (!isset($_POST['kop_ext_source_nonce']) || !wp_verify_nonce($_POST['kop_ext_source_nonce'], 'kop_ext_source_save')) {
        return;
    }
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $id)) {
        return;
    }
    $in = wp_unslash((array) ($_POST['kop_ext_source'] ?? array()));
    foreach (array_keys(kop_ext_source_fields()) as $key) {
        if ($key === 'submitted_by' || !isset($in[$key])) {
            continue;
        }
        $clean = in_array($key, array('description', 'selection'), true)
            ? sanitize_textarea_field($in[$key]) : sanitize_text_field($in[$key]);
        if ($key === 'kind') $clean = kop_ext_website_kind($clean);
        update_post_meta($id, '_kop_' . $key, $clean);
        if ($key === 'url') {
            update_post_meta($id, '_kop_url_norm', (string) kop_normalize_url($clean));
        }
    }
});

/* ---------- Shared helpers ---------- */

/** The records database helpers the public forms use. */
function kop_ext_load_record_libs() {
    $api = get_stylesheet_directory() . '/api/';
    foreach (array(
        'url-dedupe.php', 'news-mentions.php', 'news-tags.php', 'news-story-groups.php',
        'news-story-arcs.php', 'lawsuit-news-links.php', 'lawsuit-facility-links.php', 'lib-journalists.php',
        'lib-news-archive.php',
    ) as $file) {
        require_once $api . $file;
    }
}

function kop_ext_text($value, $max = 2000) {
    if (is_array($value)) {
        return '';
    }
    return mb_substr(sanitize_text_field((string) $value), 0, $max);
}

function kop_ext_textarea($value, $max = 5000) {
    if (is_array($value)) {
        return '';
    }
    return mb_substr(sanitize_textarea_field((string) $value), 0, $max);
}

function kop_ext_date($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    $ts = strtotime($value);
    return $ts ? gmdate('Y-m-d', $ts) : null;
}

/** Tags arrive as an array (popup) or comma text; always a clean list. */
function kop_ext_list($value) {
    if (is_string($value)) {
        $value = explode(',', $value);
    }
    if (!is_array($value)) {
        return array();
    }
    $out = array();
    foreach ($value as $item) {
        $item = kop_ext_text($item, 100);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return array_slice(array_values(array_unique($out)), 0, 20);
}

/** Who sent it, as the records tables write it. */
function kop_ext_submitter() {
    $user = wp_get_current_user();
    $name = $user && $user->exists() ? ($user->display_name ?: $user->user_login) : 'Unknown user';
    return mb_substr($name . ' (browser extension)', 0, 255);
}

/** Reviewer note: the sender's note, then any highlighted text. */
function kop_ext_note($p) {
    $parts = array();
    $notes = kop_ext_textarea($p['notes'] ?? '');
    if ($notes !== '') {
        $parts[] = $notes;
    }
    $selection = kop_ext_textarea($p['selection'] ?? '', 2000);
    if ($selection !== '' && strpos($notes, $selection) === false) {
        $parts[] = 'Highlighted: "' . $selection . '"';
    }
    return implode("\n\n", $parts);
}

/**
 * The records tables spell jurisdictions out ("California", "Federal"); the
 * extension reads codes from bill URLs ("CA", "US").
 */
function kop_ext_jurisdiction_name($value) {
    $value = kop_ext_text($value, 100);
    $code = strtoupper($value);
    if ($code === 'US' || $code === 'USA') {
        return 'Federal';
    }
    $states = function_exists('kop_news_tag_state_codes') ? kop_news_tag_state_codes() : array();
    return $states[$code] ?? $value;
}

/** Where a reviewer goes for a record of $type ('news', 'lawsuit', 'legislation', 'website'). */
function kop_ext_review_url($type) {
    if ($type === 'website') {
        // The Review inbox card, where it can also move to another queue.
        $inbox = function_exists('kop_submissions_review_url') ? (string) kop_submissions_review_url('websites') : '';
        return $inbox !== '' ? $inbox : admin_url('edit.php?post_type=' . KOP_EXT_SOURCE_CPT . '&post_status=pending');
    }
    return function_exists('kop_submission_review_url') ? kop_submission_review_url($type) : '';
}

/** Label for the queue a type lands in, for the extension to show. */
function kop_ext_queue_label($type) {
    $labels = array(
        'news'        => 'Submissions Review (News)',
        'lawsuit'     => 'the lawsuit review queue',
        'legislation' => 'the legislation review queue',
        'website'     => 'Websites Sent In',
    );
    return $labels[$type] ?? 'the review queue';
}

function kop_ext_find_website_duplicate($url) {
    $norm = kop_normalize_url($url);
    if ($norm === null) {
        return 0;
    }
    $ids = get_posts(array(
        'post_type'   => KOP_EXT_SOURCE_CPT,
        'post_status' => array('publish', 'pending', 'draft', 'private', 'future'),
        'fields'      => 'ids',
        'numberposts' => 1,
        'meta_key'    => '_kop_url_norm',
        'meta_value'  => $norm,
    ));
    return $ids ? (int) $ids[0] : 0;
}

/**
 * Bills: the public form keys duplicates on full_text_url only, since tracker
 * pages are shared across bills. The extension usually sends the bill's own
 * page, so it also matches the same bill number in the same jurisdiction.
 */
function kop_ext_legislation_duplicates(PDO $pdo, $url, $bill_number, $jurisdiction) {
    $found = kop_check_url_duplicates($pdo, 'legislation', array('full_text_url' => $url));
    $bill_key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $bill_number));
    $jur = kop_ext_jurisdiction_name($jurisdiction);
    if ($bill_key !== '' && $jur !== '') {
        $stmt = $pdo->prepare('SELECT id, publication_status, bill_title, bill_number, official_url, full_text_url FROM legislation WHERE LOWER(jurisdiction) = LOWER(?)');
        $stmt->execute(array($jur));
        $seen = array_column($found, null, 'id');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row_key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $row['bill_number']));
            if ($row_key === $bill_key && !isset($seen[(int) $row['id']])) {
                $found[] = array(
                    'id'     => (int) $row['id'],
                    'status' => $row['publication_status'],
                    'title'  => $row['bill_title'],
                    'url'    => $row['official_url'] ?: $row['full_text_url'],
                );
            }
        }
    }
    return $found;
}

/**
 * Everything already on file for this link, grouped by record type:
 * array of ['type', 'id', 'status', 'title', 'review_url'].
 */
function kop_ext_find_duplicates(PDO $pdo, array $p) {
    $url = (string) ($p['url'] ?? '');
    $out = array();
    $add = function ($type, array $matches) use (&$out) {
        foreach ($matches as $m) {
            $out[] = array(
                'type'       => $type,
                'id'         => (int) $m['id'],
                'status'     => (string) ($m['status'] ?? ''),
                'title'      => (string) ($m['title'] ?? ''),
                'review_url' => kop_ext_review_url($type),
            );
        }
    };

    $archive = trim((string) ($p['archive_url'] ?? ''));
    $urls = kop_collect_urls($url, $archive);
    if ($urls) {
        $add('news', kop_check_url_duplicates($pdo, 'news', $urls));
        $add('lawsuit', kop_check_url_duplicates($pdo, 'lawsuit', array('source_urls' => $url, 'document_urls' => $url)));
    }
    $title = trim((string) ($p['title'] ?? ''));
    if ($title !== '' && ($p['type'] ?? '') === 'article') {
        $seen = array();
        foreach ($out as $d) {
            if ($d['type'] === 'news') {
                $seen[$d['id']] = true;
            }
        }
        $add('news', array_filter(
            kop_news_find_title_duplicates($pdo, $title, (string) ($p['site_name'] ?? '')),
            function ($d) use ($seen) { return !isset($seen[(int) $d['id']]); }
        ));
    }
    $add('legislation', kop_ext_legislation_duplicates($pdo, $url, $p['bill_number'] ?? '', $p['jurisdiction'] ?? ''));

    if ($website = kop_ext_find_website_duplicate($url)) {
        $out[] = array(
            'type'       => 'website',
            'id'         => $website,
            'status'     => get_post_status($website),
            'title'      => get_the_title($website),
            'review_url' => get_edit_post_link($website, 'raw'),
        );
    }
    return $out;
}

/* ---------- Inserts, one per queue ---------- */

/**
 * The News Processor's fields from the extension's admin panel (reviewer route
 * only): alternate title, type, location, staff, survivors, warnings, the
 * per-type details. Every other send arrives with the basics.
 */
function kop_ext_news_full_fields(array $p) {
    if (!function_exists('kop_news_article_type_values')) require_once __DIR__ . '/choice-lists.php';
    $types = kop_news_article_type_values('all'); // KOP Tools > Dropdown Lists
    $type  = sanitize_key((string) ($p['article_type'] ?? 'general'));
    if (in_array($type, $types, true) && !in_array($type, array('lawsuit', 'event', 'expose', 'arrest', 'closure', 'corporate', 'general'), true)) {
        kop_news_article_type_column_sync(); // a type added on the screen: the enum column takes it
    }
    $out = array(
        'alternate_title'  => kop_ext_text($p['alternate_title'] ?? '', 500),
        'article_type'     => in_array($type, $types, true) ? $type : 'general',
        'article_location' => kop_ext_text($p['location'] ?? '', 255),
        'staff'            => kop_ext_list($p['staff'] ?? array()),
        'survivors'        => kop_ext_list($p['survivors'] ?? array()),
        'content_warnings' => kop_ext_list($p['content_warnings'] ?? array()),
        'facilities'       => kop_ext_list($p['facilities'] ?? array()),
        'details'          => array(),
    );
    $keys = function_exists('kop_rinbox_native_news_detail_keys') ? kop_rinbox_native_news_detail_keys() : array();
    $details = is_array($p['details'] ?? null) ? $p['details'] : array();
    foreach ($keys as $k) {
        if (isset($details[$k]) && !is_array($details[$k]) && trim((string) $details[$k]) !== '') {
            $out['details'][$k] = kop_ext_textarea($details[$k], 2000);
        }
    }
    return $out;
}

/**
 * $full: the admin panel's fields (kop_ext_news_full_fields()) plus 'status'
 * ('submitted' or 'approved'); null for every public and quick send.
 */
function kop_ext_insert_news(PDO $pdo, array $p, $submitter, $note, $full = null) {
    $title      = kop_ext_text($p['title'] ?? '', 500);
    $links      = kop_news_split_urls((string) $p['url'], (string) ($p['archive_url'] ?? ''));
    $url        = esc_url_raw($links['url']);
    $archive    = $links['archive'] !== '' ? esc_url_raw($links['archive']) : '';
    $outlet     = kop_ext_text($p['site_name'] ?? '', 255);
    $author     = kop_ext_text($p['author'] ?? '', 255);
    $date       = kop_ext_date($p['published'] ?? '');
    $tags       = kop_news_tags_normalize(kop_ext_list($p['tags'] ?? array()));
    $facility   = kop_ext_text($p['facility'] ?? '', 255);
    $names      = $facility !== '' ? array($facility) : array();
    $summary    = kop_ext_textarea(is_array($full) && isset($p['summary']) ? $p['summary'] : ($p['description'] ?? ''), 2000);
    $status     = 'submitted';
    $alt = ''; $type = 'general'; $location = ''; $staff = array(); $survivors = array(); $warnings = array();
    if (is_array($full)) {
        $alt       = $full['alternate_title'];
        $type      = $full['article_type'];
        $location  = $full['article_location'];
        $staff     = $full['staff'];
        $survivors = $full['survivors'];
        $warnings  = $full['content_warnings'];
        $names     = array_values(array_unique(array_merge($names, $full['facilities'])));
        if (($full['status'] ?? '') === 'approved') $status = 'approved';
    }
    $facilities = kop_normalize_facility_mentions($names);
    // Sent with no facility: the records the title and notes name, so the card comes in linked.
    if (!$facilities && function_exists('kop_autoconn_submission_mentions')) {
        $facilities = kop_autoconn_submission_mentions($pdo, '', $title . "
" . $note . "
" . $summary);
    }
    $approved   = $status === 'approved';

    $json = array(
        'title' => $title, 'url' => $url, 'archiveUrl' => $archive, 'publicationName' => $outlet, 'author' => $author,
        'publicationDate' => $date, 'tags' => $tags, 'facilities' => $facilities,
        'summary' => $summary, 'source' => 'browser-extension',
    );
    if (is_array($full)) {
        $json += array(
            'alternateTitle' => $alt, 'needsAlternateTitle' => $alt !== '', 'articleType' => $type, 'location' => $location,
            'staff' => $staff, 'survivors' => $survivors, 'contentWarnings' => $warnings,
        );
        $json += $full['details'];
    }

    kop_news_archive_ensure($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO news_submissions
            (article_title, alternate_title, author, publication_name, publication_date,
             article_url, archive_url, article_type, article_location, tags, facilities_mentioned, staff_mentioned,
             survivors_mentioned, content_warnings, summary, json_data,
             generated_output, status, submitted_by, submission_notes, reviewed_by, reviewed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?)"
    );
    $stmt->execute(array(
        $title, $alt, $author, $outlet, $date, $url, $archive !== '' ? $archive : null, $type, $location,
        wp_json_encode($tags, JSON_UNESCAPED_UNICODE),
        wp_json_encode($facilities, JSON_UNESCAPED_UNICODE),
        wp_json_encode($staff, JSON_UNESCAPED_UNICODE),
        wp_json_encode($survivors, JSON_UNESCAPED_UNICODE),
        wp_json_encode($warnings, JSON_UNESCAPED_UNICODE),
        $summary,
        wp_json_encode($json, JSON_UNESCAPED_UNICODE),
        $status, $submitter, $note,
        $approved ? $submitter : null,
        $approved ? current_time('mysql') : null,
    ));
    $id = (int) $pdo->lastInsertId();

    // Published from the extension: listed in Recently done, with the same Undo as an approval on the review page.
    if ($approved && function_exists('kop_rinbox_log_native')) {
        kop_rinbox_log_native('news', array($id => array('status' => 'submitted', 'title' => $title)), 'approve');
    }

    // The same follow-ups api/save-news-submission.php runs on insert. The row
    // is saved by now, so a failed link sync is logged, never reported as a
    // failed send (a retry would only hit the duplicate check).
    $follow_ups = array(
        'facility links' => function () use ($pdo, $id, $facilities, $submitter) { kop_sync_news_facility_links($pdo, $id, $facilities, $submitter); },
        'story group'    => function () use ($pdo, $id) { kop_news_assign_story_group($pdo, $id); },
        'story arc'      => function () use ($pdo, $id) { kop_news_assign_story_arc($pdo, $id); },
        'lawsuit links'  => function () use ($pdo, $id, $submitter) { kop_sync_news_lawsuit_links($pdo, $id, $submitter); },
        'journalists'    => function () use ($pdo, $id) { kop_journalist_sync_article_safe($pdo, $id); },
    );
    foreach ($follow_ups as $what => $run) {
        try {
            $run();
        } catch (Throwable $e) {
            error_log('kop extension: news #' . $id . ' ' . $what . ' failed: ' . $e->getMessage());
        }
    }

    if (!$approved && function_exists('kop_notify_admins') && empty($GLOBALS['kop_ext_quiet_notify'])) {
        kop_notify_admins('news', $title, '', array(
            'Publication' => $outlet, 'URL' => $url, 'Submitted by' => $submitter, 'Reference' => '#' . $id,
        ));
    }
    return $id;
}

/** A lawsuit's facilities_mentioned: the name sent, else the records its title and notes name. */
function kop_ext_lawsuit_mentions(PDO $pdo, $facility, $text) {
    if ($facility !== '') return array($facility);
    return function_exists('kop_autoconn_submission_mentions') ? kop_autoconn_submission_mentions($pdo, '', $text) : array();
}

function kop_ext_insert_lawsuit(PDO $pdo, array $p, $submitter, $note) {
    $url      = esc_url_raw((string) $p['url']);
    $facility = kop_ext_text($p['facility'] ?? '', 255);
    $list     = function (array $items) {
        return wp_json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $fields = array(
        'case_name'            => kop_ext_text($p['title'] ?? '', 500),
        'case_number'          => kop_ext_text($p['case_number'] ?? '', 255),
        'court'                => kop_ext_text($p['court'] ?? '', 255),
        'jurisdiction'         => kop_ext_jurisdiction_name($p['jurisdiction'] ?? ''),
        'status'               => 'unknown',
        'plaintiffs'           => '[]',
        'defendants'           => '[]',
        'facilities_mentioned' => $list(kop_ext_lawsuit_mentions($pdo, $facility, (string) ($p['title'] ?? '') . "
" . $note . "
" . (string) ($p['description'] ?? ''))),
        'staff_mentioned'      => '[]',
        'organizations_mentioned' => '[]',
        'claims'               => '[]',
        'outcome'              => '',
        'settlement_amount'    => '',
        'summary'            => kop_ext_textarea($p['description'] ?? '', 2000),
        'source_urls'          => $list(array($url)),
        'document_urls'        => '[]',
        'tags'                 => $list(kop_ext_list($p['tags'] ?? array())),
        'publication_status'   => 'pending',
        'submitted_by'         => $submitter,
        'reviewer_notes'       => $note !== '' ? '[submitter] ' . $note : '',
    );
    $cols = array_keys($fields);
    $pdo->prepare('INSERT INTO lawsuits (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($fields));
    $id = (int) $pdo->lastInsertId();

    try {
        kop_sync_lawsuit_facility_links($pdo, $id, $fields['facilities_mentioned'], 'browser-extension');
    } catch (Throwable $e) {
        error_log('kop extension: lawsuit facility links failed: ' . $e->getMessage());
    }
    if (function_exists('kop_notify_admins')) {
        kop_notify_admins('lawsuit', $fields['case_name'], '', array(
            'Case number' => $fields['case_number'], 'Court' => $fields['court'],
            'URL' => $url, 'Submitted by' => $submitter, 'Reference' => '#' . $id,
        ));
    }
    return $id;
}

function kop_ext_insert_legislation(PDO $pdo, array $p, $submitter, $note) {
    $url      = esc_url_raw((string) $p['url']);
    $facility = kop_ext_text($p['facility'] ?? '', 255);
    $list     = function (array $items) {
        return wp_json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $fields = array(
        'bill_number'         => kop_ext_text($p['bill_number'] ?? '', 100),
        'bill_title'          => kop_ext_text($p['title'] ?? '', 500),
        'jurisdiction'        => kop_ext_jurisdiction_name($p['jurisdiction'] ?? ''),
        'chamber'             => 'unknown',
        'session_year'        => kop_ext_text($p['session'] ?? '', 50),
        'bill_type'           => '',
        'sponsors'            => '[]',
        'status'              => 'unknown',
        'introduced_date'     => null,
        'last_action_date'    => null,
        'last_action_text'    => '',
        'subject_tags'        => '[]',
        'summary'             => kop_ext_textarea($p['description'] ?? '', 2000),
        'full_text_url'       => '',
        'official_url'        => $url,
        'position'            => 'unknown',
        'facilities_affected' => $list($facility !== '' ? array($facility) : array()),
        'tags'                => $list(kop_ext_list($p['tags'] ?? array())),
        'publication_status'  => 'pending',
        'submitted_by'        => $submitter,
        'reviewer_notes'      => $note !== '' ? '[submitter] ' . $note : '',
    );
    $cols = array_keys($fields);
    $pdo->prepare('INSERT INTO legislation (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($fields));
    $id = (int) $pdo->lastInsertId();

    if (function_exists('kop_notify_admins')) {
        kop_notify_admins('legislation', $fields['bill_title'], '', array(
            'Bill number' => $fields['bill_number'], 'Jurisdiction' => $fields['jurisdiction'],
            'URL' => $url, 'Submitted by' => $submitter, 'Reference' => '#' . $id,
        ));
    }
    return $id;
}

function kop_ext_insert_website(array $p, $submitter, $note) {
    $url   = esc_url_raw((string) $p['url']);
    $title = kop_ext_text($p['title'] ?? '', 300) ?: $url;
    $id = wp_insert_post(array(
        'post_type'    => KOP_EXT_SOURCE_CPT,
        'post_status'  => 'pending',
        'post_title'   => $title,
        'post_content' => $note,
        'post_author'  => get_current_user_id(),
    ), true);
    if (is_wp_error($id)) {
        return $id;
    }
    $values = array(
        'url'          => $url,
        // website_kind from the sender (mobile/submit, the extension), else what the address says.
        'kind'         => kop_ext_website_kind($p['website_kind'] ?? '') ?: kop_ext_website_guess_kind($url),
        'site_name'    => kop_ext_text($p['site_name'] ?? '', 255),
        'author'       => kop_ext_text($p['author'] ?? '', 255),
        'published'    => (string) kop_ext_date($p['published'] ?? ''),
        'facility'     => kop_ext_text($p['facility'] ?? '', 255),
        'tags'         => implode(', ', kop_ext_list($p['tags'] ?? array())),
        'description'  => kop_ext_textarea($p['description'] ?? '', 2000),
        'selection'    => kop_ext_textarea($p['selection'] ?? '', 2000),
        'submitted_by' => $submitter,
    );
    foreach ($values as $key => $value) {
        if ($value !== '') {
            update_post_meta($id, '_kop_' . $key, $value);
        }
    }
    update_post_meta($id, '_kop_url_norm', (string) kop_normalize_url($url));

    if (function_exists('kop_notify_admins')) {
        kop_notify_admins('website', $title, kop_ext_review_url('website'), array(
            'URL' => $url, 'Facility' => $values['facility'], 'Submitted by' => $submitter,
        ));
    }
    return (int) $id;
}

/* ---------- REST routes ---------- */

function kop_ext_rest_submit(WP_REST_Request $req) {
    $p = $req->get_json_params();
    if (!is_array($p)) {
        $p = array();
    }
    $url = esc_url_raw(trim((string) ($p['url'] ?? '')), array('http', 'https'));
    if ($url === '' || !wp_http_validate_url($url)) {
        return new WP_Error('kop_bad_url', 'A valid http or https link is required.', array('status' => 400));
    }
    $p['url'] = $url;
    $type = sanitize_key((string) ($p['type'] ?? 'website'));
    if (!in_array($type, array('website', 'article', 'lawsuit', 'legislation'), true)) {
        $type = 'website';
    }
    $p['type'] = $type;
    if (trim((string) ($p['title'] ?? '')) === '') {
        $p['title'] = $url;
    }

    kop_ext_load_record_libs();
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        return new WP_Error('kop_no_db', 'The records database is not reachable.', array('status' => 500));
    }

    try {
        $dupes = kop_ext_find_duplicates($pdo, $p);
        if ($dupes) {
            return new WP_REST_Response(array(
                'code'       => 'kop_duplicate',
                'message'    => 'This link is already in the database.',
                'duplicates' => $dupes,
                'review_url' => $dupes[0]['review_url'],
            ), 409);
        }

        $submitter = kop_ext_submitter();
        $note = kop_ext_note($p);
        switch ($type) {
            case 'article':
                // The extension's admin panel (inc/extension-news-processor.php) sends the processor's fields.
                $full = null;
                if (!empty($p['full'])) {
                    $full = kop_ext_news_full_fields($p);
                    $full['status'] = !empty($p['publish']) && current_user_can('manage_options') ? 'approved' : 'submitted';
                }
                $id = kop_ext_insert_news($pdo, $p, $submitter, $note, $full);
                $queue = $full && $full['status'] === 'approved' ? 'news_published' : 'news';
                break;
            case 'lawsuit':
                $id = kop_ext_insert_lawsuit($pdo, $p, $submitter, $note);
                $queue = 'lawsuit';
                break;
            case 'legislation':
                $id = kop_ext_insert_legislation($pdo, $p, $submitter, $note);
                $queue = 'legislation';
                break;
            default:
                $id = kop_ext_insert_website($p, $submitter, $note);
                $queue = 'website';
        }
    } catch (Throwable $e) {
        error_log('kop extension submit failed: ' . $e->getMessage());
        return new WP_Error('kop_save_failed', 'The site could not save this. Try again, or add it by hand.', array('status' => 500));
    }
    if (is_wp_error($id)) {
        return $id;
    }

    if ($queue === 'news_published') {
        return new WP_REST_Response(array(
            'id'         => $id,
            'type'       => 'news',
            'published'  => true,
            'queue'      => 'the published news',
            'review_url' => kop_ext_review_url('news'),
        ), 201);
    }
    return new WP_REST_Response(array(
        'id'         => $id,
        'type'       => $queue,
        'queue'      => kop_ext_queue_label($queue),
        'review_url' => $queue === 'website' ? get_edit_post_link($id, 'raw') : kop_ext_review_url($queue),
    ), 201);
}

function kop_ext_rest_check(WP_REST_Request $req) {
    kop_ext_load_record_libs();
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        return new WP_Error('kop_no_db', 'The records database is not reachable.', array('status' => 500));
    }
    try {
        $dupes = kop_ext_find_duplicates($pdo, array(
            'url'          => (string) $req['url'],
            'archive_url'  => (string) $req['archive_url'],
            'title'        => (string) $req['title'],
            'site_name'    => (string) $req['site_name'],
            'type'         => (string) $req['type'],
            'bill_number'  => (string) $req['bill_number'],
            'jurisdiction' => (string) $req['jurisdiction'],
        ));
    } catch (Throwable $e) {
        error_log('kop extension check failed: ' . $e->getMessage());
        return new WP_Error('kop_check_failed', 'The duplicate check failed.', array('status' => 500));
    }
    return array(
        'duplicate'  => !empty($dupes),
        'duplicates' => $dupes,
        'review_url' => $dupes ? $dupes[0]['review_url'] : null,
        'user'       => wp_get_current_user()->display_name,
        'can_publish' => current_user_can('manage_options'),
    );
}

add_action('rest_api_init', function () {
    $can = function () {
        return current_user_can('edit_posts');
    };
    register_rest_route('kop/v1', '/extension/submit', array(
        'methods'             => 'POST',
        'permission_callback' => $can,
        'callback'            => 'kop_ext_rest_submit',
    ));
    register_rest_route('kop/v1', '/extension/check', array(
        'methods'             => 'GET',
        'permission_callback' => $can,
        'callback'            => 'kop_ext_rest_check',
        'args'                => array(
            'url' => array('required' => true, 'sanitize_callback' => 'esc_url_raw'),
        ),
    ));
});

// Links this sends arrive bare (title, address, site); the hourly job fills in the rest.
require_once __DIR__ . '/record-enrich.php';
