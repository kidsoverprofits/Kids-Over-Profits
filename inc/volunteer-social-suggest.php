<?php
/**
 * "Suggest as a post" (owner, 2026-10-26: post ideas are "not just stuff we
 * added to the site this week. it can be news, selected facts, facility
 * features, inspection report findings, all kinds of stuff"). An admin on any
 * public page presses "Suggest as a post" in the admin bar
 * (js/social-suggest.js): the text they selected becomes the card's quote,
 * their note becomes what the post should say, and the page (with the nearest
 * anchor, e.g. /severe-reports/#finding-123) becomes the card's link.
 *
 * The card joins the designer's open "Suggested posts" project (source
 * 'social:suggested', so everything in inc/volunteer-social.php treats it as a
 * social project: drafting, uploads, Approve / Send back, the sweep). The
 * designer is the one picked for the weekly roundup (kop_vsoc_designer());
 * with nobody picked a suggestion is refused. A closed project means the next
 * suggestion starts a new one. Card keys are 'suggested:<hash of link + text>',
 * so the same idea twice is refused. Tested by scripts/test-volunteer-projects.php
 * ("Suggested posts").
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_VSOC_SUGGEST_SOURCE = 'social:suggested';

/** The designer's open "Suggested posts" project, made when there is none: the project row. */
function kop_vsoc_suggest_project($vid) {
    global $wpdb;
    kop_volp_ensure_tables();
    $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_volp_table() . " WHERE source = %s AND volunteer_id = %d AND status = 'open' ORDER BY id DESC LIMIT 1",
        KOP_VSOC_SUGGEST_SOURCE, (int) $vid), ARRAY_A);
    if ($p) return $p;
    $brief = kop_vsoc_brief();
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $wpdb->insert(kop_volp_table(), array(
        'title' => 'Suggested posts', 'source' => KOP_VSOC_SUGGEST_SOURCE, 'search' => '',
        'question' => $brief['question'], 'yes_example' => $brief['yes'], 'no_example' => $brief['no'], 'note' => '',
        'minutes' => 15, 'volunteer_id' => (int) $vid, 'status' => 'open',
        'created_by' => $u && $u->exists() ? $u->user_login : 'suggested', 'created_at' => current_time('mysql', true),
    ));
    return kop_volp_get((int) $wpdb->insert_id);
}

/**
 * Turn what an admin picked on a page into a card and add it to the designer's
 * project. $in: url (required), title (the page's), text (selected, optional),
 * note (what the post should say, optional; one of text or note is needed).
 * Returns [project id, card].
 */
function kop_vsoc_suggest(array $in) {
    $url = trim((string) ($in['url'] ?? ''));
    if (!preg_match('#^https?://\S+$#i', $url)) throw new RuntimeException('The page link is missing.');
    $title = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) ($in['title'] ?? ''))));
    $title = trim(preg_replace('/\s*[|\x{2013}\x{2014}-]\s*Kids Over Profits\s*$/iu', '', $title));
    $text = trim(preg_replace('/[ \t]+/', ' ', str_replace("\r\n", "\n", (string) ($in['text'] ?? ''))));
    $note = trim(mb_substr((string) ($in['note'] ?? ''), 0, 1000));
    if ($text === '' && $note === '') throw new RuntimeException('Select the words the post is about, or write what it should say.');
    $vid = kop_vsoc_designer();
    if (!$vid) throw new RuntimeException('Nobody gets post ideas yet: pick a person under "Weekly social media roundup" at KOP Tools > Volunteer Reviewers > Projects.');

    $key = 'suggested:' . substr(md5($url . "\n" . $text . "\n" . $note), 0, 12);
    $card = array(
        'angle' => 'suggested', 'angle_label' => 'Suggested by us',
        'fact' => $note !== '' ? $note : kop_vsoc_cut($text, 280),
        'url' => $url, 'url_label' => $title !== '' ? $title : 'The page',
        'summary' => '', 'quote' => $note !== '' && $text !== '' ? kop_vsoc_cut($text, 700) : '',
        'image' => kop_vsoc_suggest_image($url), 'when' => '', 'key' => $key,
    );

    global $wpdb;
    $p = kop_vsoc_suggest_project($vid);
    foreach (kop_volp_item_rows($p['id'], true) as $r) {
        if ($r['item_key'] === $key) throw new RuntimeException('That one is suggested already.');
    }
    $pos = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(position), -1) FROM ' . kop_volp_items_table() . ' WHERE project_id = %d', (int) $p['id']));
    $wpdb->insert(kop_volp_items_table(), array('project_id' => (int) $p['id'], 'item_key' => $key, 'title' => mb_substr((string) $card['fact'], 0, 255),
        'status' => '', 'position' => $pos + 1, 'detail' => wp_json_encode($card)));
    return array((int) $p['id'], $card);
}

/** The page's own picture when the link is one of our posts or pages: its featured image. */
function kop_vsoc_suggest_image($url) {
    if (!function_exists('url_to_postid')) return '';
    $id = (int) url_to_postid(preg_replace('/#.*$/', '', (string) $url));
    if (!$id || !function_exists('get_the_post_thumbnail_url')) return '';
    return (string) (get_the_post_thumbnail_url($id, 'large') ?: '');
}

/** Whoever can suggest: admins, on the public site. */
function kop_vsoc_suggest_can() {
    return is_user_logged_in() && current_user_can('manage_options');
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/volunteer/suggest-post', array(
        'methods' => 'POST', 'callback' => 'kop_vsoc_rest_suggest',
        'permission_callback' => 'kop_vsoc_suggest_can',
    ));
});

function kop_vsoc_rest_suggest(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $in = array();
        foreach (array('url', 'title', 'text', 'note') as $k) $in[$k] = wp_unslash((string) $req->get_param($k));
        list($pid, ) = kop_vsoc_suggest($in);
        global $wpdb;
        $name = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . kop_vol_table() . ' WHERE id = %d', kop_vsoc_designer()));
        return array('project_id' => $pid, 'message' => 'Sent to ' . ($name !== '' ? $name : 'the designer') . ' as a post idea.',
            'admin' => admin_url('admin.php?page=kop-volunteer-reviewers&project=' . $pid));
    });
}

/* The admin bar item, on the public site only. */
add_action('admin_bar_menu', function ($bar) {
    if (is_admin() || !kop_vsoc_suggest_can()) return;
    $bar->add_node(array('id' => 'kop-suggest-post', 'title' => 'Suggest as a post', 'href' => '#kop-suggest-post',
        'meta' => array('title' => 'Select the words a post should be about, then press this')));
}, 95);

add_action('wp_enqueue_scripts', function () {
    if (!kop_vsoc_suggest_can()) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    if (!file_exists($dir . '/js/social-suggest.js')) return;
    wp_enqueue_style('kop-social-suggest', $uri . '/css/social-suggest.css', array(), filemtime($dir . '/css/social-suggest.css'));
    wp_enqueue_script('kop-social-suggest', $uri . '/js/social-suggest.js', array(), filemtime($dir . '/js/social-suggest.js'), true);
    wp_add_inline_script('kop-social-suggest', 'window.KOP_SOCIAL_SUGGEST = ' . wp_json_encode(array(
        'rest' => esc_url_raw(rest_url('kop/v1/volunteer/suggest-post')), 'nonce' => wp_create_nonce('wp_rest'),
    )) . ';', 'before');
}, 50);
