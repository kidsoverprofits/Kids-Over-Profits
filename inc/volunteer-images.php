<?php
/**
 * Photo projects: a volunteer project (inc/volunteer-projects.php) whose items
 * are spots on our hub and article pages that have no picture yet (the page's
 * header, or a section after one heading), listed in seeds/image-spots.json.
 * The volunteer finds an openly licensed picture for a spot and says where it
 * is (the page that shows it and its license), who made it, the license and a
 * line for people who cannot see it; or that they could not find one, and
 * where they looked. An admin approves it or sends it back with a note.
 *
 * Unlike research answers, these links never go to the review inbox as
 * sources (docs/plan 4.4 item 10c). Approve copies the picture into the media
 * library (Wikimedia Commons files through the Commons API, at most 2000 px
 * wide; anything else from the file link they gave), captioned with the
 * credit line the earlier photo rounds used ("Author, License, via Wikimedia
 * Commons"), _kop_source_url = their page link, filed in FileBird's "Site
 * Photos", and places it the way seeds/page-images.json does (featured image,
 * or an image block after the heading; inc/page-images.php). Undo approve
 * takes it off the page again; the media library copy stays.
 *
 * A spot already showing a picture, or in another open photo project, is never
 * offered. A project can be anyone's (every volunteer may send a pick; the
 * first approved one fills the spot for everyone) or one person's.
 *
 * Source 'images:pages'. Table {prefix}kop_vol_images (one row per spot per
 * volunteer: status draft | ready | approved | sent_back | not_found | passed).
 * REST: kop/v1/volunteer/image. Tested by scripts/test-volunteer-projects.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/page-images.php';

const KOP_VIMG_DB_VERSION = '1';
const KOP_VIMG_SOURCE = 'images:pages';
const KOP_VIMG_MAX_WIDTH = 2000;

function kop_vimg_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_vol_images';
}

function kop_vimg_ensure_tables() {
    if (get_option('kop_vol_images_db') === KOP_VIMG_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = kop_vimg_table();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        project_id BIGINT UNSIGNED NOT NULL,
        item_key VARCHAR(191) NOT NULL,
        volunteer_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        page_url VARCHAR(500) NOT NULL DEFAULT '',
        file_url VARCHAR(500) NOT NULL DEFAULT '',
        author VARCHAR(255) NOT NULL DEFAULT '',
        license VARCHAR(100) NOT NULL DEFAULT '',
        alt VARCHAR(300) NOT NULL DEFAULT '',
        note TEXT NULL,
        owner_note TEXT NULL,
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        decided_by VARCHAR(60) NOT NULL DEFAULT '',
        decided_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY one_each (project_id, item_key, volunteer_id),
        KEY status (status)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('kop_vol_images_db', KOP_VIMG_DB_VERSION);
}

function kop_vimg_is(array $p) {
    return strpos((string) $p['source'], 'images:') === 0;
}

/** The volunteer's work on a spot is done for now. */
function kop_vimg_done_statuses() {
    return array('ready', 'approved', 'not_found', 'passed');
}

function kop_vimg_brief() {
    return array(
        'title'    => 'Photos for our pages',
        'question' => 'Find an openly licensed picture for each spot: one that shows what the page or section is about. Give the link to the page that shows the picture and its license, who made it, the license, and one line describing it for people who cannot see it.',
        'yes'      => 'A 1960s photo of a Senate hearing room on Wikimedia Commons, license "Public domain", by the U.S. Senate Historical Office, with the link to its Commons page. Historical pictures for history pages, recent ones for recent topics.',
        'no'       => 'A license with NC (non-commercial) or ND (no changes) in it, "All rights reserved", a watermark, anything from an image search with no license shown, AI-made pictures, a recent photo where a child\'s face can be seen, or a picture that has little to do with the page.',
    );
}

/* ---- The spots ------------------------------------------------------------------------- */

function kop_vimg_seed_path() {
    return trailingslashit(get_stylesheet_directory()) . 'seeds/image-spots.json';
}

/** Every spot in the seed, keyed: {key, page, heading ('' = the header), about, ideas}. */
function kop_vimg_spots() {
    $raw = json_decode((string) @file_get_contents(kop_vimg_seed_path()), true);
    $out = array();
    foreach ((array) $raw as $s) {
        $page = sanitize_title((string) ($s['page'] ?? ''));
        if ($page === '') continue;
        $heading = trim((string) ($s['heading'] ?? ''));
        $key = $page . '#' . ($heading === '' ? 'header' : sanitize_title($heading));
        $out[$key] = array('key' => $key, 'page' => $page, 'heading' => $heading, 'about' => (string) ($s['about'] ?? ''),
            'ideas' => array_values(array_filter(array_map('strval', (array) ($s['ideas'] ?? array())))));
    }
    return $out;
}

/** The published page with this slug: {ID, post_title, post_content} or null. */
function kop_vimg_page($slug) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT ID, post_title, post_content FROM {$wpdb->posts}
        WHERE post_name = %s AND post_type = 'page' AND post_status = 'publish' ORDER BY ID LIMIT 1", (string) $slug), ARRAY_A);
    return $row ?: null;
}

/**
 * Where a spot stands on the live page: 'open' (no picture yet), 'filled' (a
 * featured image, or an image block right after the heading), 'no-page', or
 * 'no-heading' (the heading is not there exactly once).
 */
function kop_vimg_spot_state(array $spot) {
    $page = kop_vimg_page($spot['page']);
    if (!$page) return 'no-page';
    global $wpdb;
    if ($spot['heading'] === '') {
        $thumb = (int) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_thumbnail_id' LIMIT 1", (int) $page['ID']));
        return $thumb ? 'filled' : 'open';
    }
    $want = kop_page_images_heading_text($spot['heading']);
    $re = '#<!-- wp:heading[^>]*-->\s*<h([1-6])[^>]*>(.*?)</h\1>\s*<!-- /wp:heading -->#s';
    $after = array();
    if (preg_match_all($re, (string) $page['post_content'], $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[2] as $i => $inner) {
            if (kop_page_images_heading_text($inner[0]) === $want) $after[] = $m[0][$i][1] + strlen($m[0][$i][0]);
        }
    }
    if (count($after) !== 1) return 'no-heading';
    return preg_match('#^\s*<!-- wp:image#', substr((string) $page['post_content'], $after[0], 400)) ? 'filled' : 'open';
}

/** Spot keys with an approved pick in any project. */
function kop_vimg_approved_keys() {
    global $wpdb;
    kop_vimg_ensure_tables();
    return array_fill_keys(array_map('strval', (array) $wpdb->get_col('SELECT DISTINCT item_key FROM ' . kop_vimg_table() . " WHERE status = 'approved'")), true);
}

/** Spots still without a picture: [key => spot + page_title, page_url], and why the others are out (for the admin). */
function kop_vimg_open_spots() {
    $open = array();
    $why = array('filled' => 0, 'claimed' => 0, 'gone' => array());
    $claimed = kop_volp_claimed_keys(KOP_VIMG_SOURCE);
    $approved = kop_vimg_approved_keys();
    foreach (kop_vimg_spots() as $key => $s) {
        $state = kop_vimg_spot_state($s);
        if ($state === 'no-page' || $state === 'no-heading') { $why['gone'][] = $key . ' (' . $state . ')'; continue; }
        if ($state === 'filled' || isset($approved[$key])) { $why['filled']++; continue; }
        if (isset($claimed[$key])) { $why['claimed']++; continue; }
        $page = kop_vimg_page($s['page']);
        $open[$key] = $s + array('page_title' => html_entity_decode(wp_strip_all_tags((string) $page['post_title']), ENT_QUOTES, 'UTF-8'),
            'page_url' => home_url('/' . $s['page'] . '/'));
    }
    return array($open, $why);
}

/* ---- Making a project ------------------------------------------------------------------- */

/** A photo project of every open spot, for one volunteer or ($vid 0) anyone with a link. Returns [id, link, '']. */
function kop_vimg_make_project($vid = 0, $note = '') {
    $vid = (int) $vid;
    global $wpdb;
    if ($vid) {
        kop_vol_ensure_tables();
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . kop_vol_table() . ' WHERE id = %d AND revoked_at IS NULL', $vid))) throw new RuntimeException('That volunteer is turned off or gone.');
    }
    list($spots) = kop_vimg_open_spots();
    if (!$spots) throw new RuntimeException('Every spot in seeds/image-spots.json has a picture already or is in an open photo project.');
    kop_volp_ensure_tables();
    $brief = kop_vimg_brief();
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $wpdb->insert(kop_volp_table(), array(
        'title' => $brief['title'], 'source' => KOP_VIMG_SOURCE, 'search' => '',
        'question' => $brief['question'], 'yes_example' => $brief['yes'], 'no_example' => $brief['no'], 'note' => trim(mb_substr((string) $note, 0, 1000)),
        'minutes' => count($spots) * 10, 'volunteer_id' => $vid, 'status' => 'open',
        'created_by' => $u && $u->exists() ? $u->user_login : '', 'created_at' => current_time('mysql', true),
    ));
    $id = (int) $wpdb->insert_id;
    $n = 0;
    foreach ($spots as $key => $s) {
        $title = $s['page_title'] . ($s['heading'] !== '' ? ': ' . $s['heading'] : ' (top of the page)');
        $wpdb->insert(kop_volp_items_table(), array('project_id' => $id, 'item_key' => (string) $key, 'title' => mb_substr($title, 0, 255),
            'status' => '', 'position' => $n++, 'detail' => wp_json_encode($s)));
    }
    return array($id, kop_volp_url($id), '');
}

/* ---- What the volunteer sees ------------------------------------------------------------- */

function kop_vimg_get_pick($pid, $key, $vid) {
    global $wpdb;
    kop_vimg_ensure_tables();
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_vimg_table() . ' WHERE project_id = %d AND item_key = %s AND volunteer_id = %d', (int) $pid, (string) $key, (int) $vid), ARRAY_A);
    return $r ?: null;
}

function kop_vimg_public_pick(array $r) {
    $out = array();
    foreach (array('status', 'page_url', 'file_url', 'author', 'license', 'alt', 'note', 'owner_note') as $k) $out[$k] = (string) $r[$k];
    return $out;
}

/** Keys this volunteer is done with for now, and every spot someone's approved pick has filled. */
function kop_vimg_done_keys($pid, $vid) {
    global $wpdb;
    kop_vimg_ensure_tables();
    $in = "'" . implode("','", kop_vimg_done_statuses()) . "'";
    $keys = $vid ? (array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_vimg_table() . " WHERE project_id = %d AND volunteer_id = %d AND status IN ($in)", (int) $pid, (int) $vid)) : array();
    $keys = array_merge($keys, (array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_vimg_table() . " WHERE project_id = %d AND status = 'approved'", (int) $pid)));
    return array_fill_keys(array_map('strval', $keys), true);
}

/** Keys anyone has touched in a project (handing back keeps these). */
function kop_vimg_touched_keys($pid) {
    global $wpdb;
    kop_vimg_ensure_tables();
    return array_fill_keys(array_map('strval', (array) $wpdb->get_col($wpdb->prepare('SELECT DISTINCT item_key FROM ' . kop_vimg_table() . ' WHERE project_id = %d', (int) $pid))), true);
}

/** One spot as the page gets it: what it is, where to look, and the volunteer's pick. */
function kop_vimg_item(array $p, array $row, $vid) {
    $s = json_decode((string) ($row['detail'] ?? ''), true);
    if (!is_array($s)) return null;
    $out = array('source' => 'image', 'project_id' => (int) $p['id'], 'key' => (string) $row['item_key'], 'title' => (string) $row['title'], 'pick' => null,
        'page_title' => (string) ($s['page_title'] ?? ''), 'heading' => (string) ($s['heading'] ?? ''), 'page_url' => (string) ($s['page_url'] ?? ''),
        'about' => (string) ($s['about'] ?? ''), 'ideas' => array_values(array_map('strval', (array) ($s['ideas'] ?? array()))));
    if ($vid) {
        $r = kop_vimg_get_pick((int) $p['id'], $row['item_key'], $vid);
        if ($r) $out['pick'] = kop_vimg_public_pick($r);
    }
    return $out;
}

/** The next spots this volunteer has not finished: new ones, drafts, ones sent back. */
function kop_vimg_items(array $p, $vid, $limit, array $skip) {
    $mine = array_fill_keys(array_map('strval', $skip), true) + kop_vimg_done_keys((int) $p['id'], (int) $vid);
    $items = array();
    foreach (kop_volp_item_rows($p['id'], true) as $r) {
        if ((int) $r['gone'] || isset($mine[$r['item_key']])) continue;
        $it = kop_vimg_item($p, $r, $vid);
        if (!$it) continue;
        $items[] = $it;
        if (count($items) >= $limit) break;
    }
    return $items;
}

/** The spot's project row, checked as open to this volunteer: [project, item row]. */
function kop_vimg_spot(array $vol, $pid, $key) {
    $p = kop_volp_get($pid);
    if (!$p || !kop_vimg_is($p) || !kop_volp_for_volunteer($p, (int) $vol['id'])) throw new RuntimeException('This project is finished or not yours. Thank you!');
    foreach (kop_volp_item_rows($pid, true) as $r) if ($r['item_key'] === (string) $key) return array($p, $r);
    throw new RuntimeException('That spot is not in this project any more.');
}

/**
 * Is this a license we can use? Public domain, CC0, CC BY, CC BY-SA, the free
 * licenses of the big free photo sites, and works of the U.S. government;
 * never NC, ND or all rights reserved.
 */
function kop_vimg_license_ok($license) {
    $l = strtolower(trim((string) $license));
    if ($l === '' || preg_match('/\b(nc|nd)\b|non-?commercial|no ?deriv|all rights reserved|editorial|fair use/', $l)) return false;
    return (bool) preg_match('/public domain|\bpd\b|pdm|cc0|cc[ -]?by|creative commons attribution|no known copyright|u\.?\s?s\.? government|federal government|pexels|unsplash|pixabay|gfdl/', $l);
}

/** A Wikimedia Commons file named by a link to its page or the file itself: 'File:Name.jpg', or ''. */
function kop_vimg_commons_title($url) {
    $url = (string) $url;
    if (preg_match('#^https?://commons(?:\.m)?\.wikimedia\.org/wiki/(File:[^?\#]+)#i', $url, $m)) return str_replace('_', ' ', rawurldecode($m[1]));
    if (preg_match('#^https?://upload\.wikimedia\.org/wikipedia/commons/(?:thumb/)?[0-9a-f]/[0-9a-f]{2}/([^/?\#]+)#i', $url, $m)) return 'File:' . str_replace('_', ' ', rawurldecode($m[1]));
    return '';
}

/** Commons page link in the form the page-images seed uses (wiki/File:Name_with_underscores.jpg). */
function kop_vimg_commons_page($title) {
    return 'https://commons.wikimedia.org/wiki/' . str_replace(' ', '_', $title);
}

/**
 * The volunteer works on one spot. $do: draft (save), ready (send for review:
 * needs the page link, a file link unless it is on Commons, who made it, a
 * license we can use, and the line for people who cannot see it), not_found
 * (needs where they looked, in the note), pass (not for them), back (take a
 * sent one back).
 */
function kop_vimg_save(array $vol, $pid, $key, $do, array $in = array()) {
    list(, $row) = kop_vimg_spot($vol, $pid, $key);
    if (!in_array($do, array('draft', 'ready', 'not_found', 'pass', 'back'), true)) throw new RuntimeException('Unknown step.');
    kop_vol_rate_check((int) $vol['id']);
    $old = kop_vimg_get_pick($pid, $key, (int) $vol['id']);
    if ($old && $old['status'] === 'approved') throw new RuntimeException('This one is approved already.');
    if ($do === 'back' && (!$old || !in_array($old['status'], array('ready', 'not_found'), true))) throw new RuntimeException('Only a pick waiting for review can be taken back.');
    if (isset(kop_vimg_done_keys((int) $pid, 0)[(string) $key])) throw new RuntimeException('Someone else\'s picture was approved for this spot already. Thank you!');

    $f = array();
    foreach (array('page_url' => 500, 'file_url' => 500, 'author' => 255, 'license' => 100, 'alt' => 300, 'note' => 2000) as $k => $max) {
        $f[$k] = array_key_exists($k, $in) ? trim(mb_substr(str_replace("\r\n", "\n", (string) $in[$k]), 0, $max)) : (string) ($old[$k] ?? '');
    }
    foreach (array('page_url' => 'The link to the picture\'s page', 'file_url' => 'The link to the picture file') as $k => $what) {
        if ($f[$k] !== '' && !preg_match('#^https?://\S+$#i', $f[$k])) throw new RuntimeException($what . ' should start with https://');
    }
    $commons = kop_vimg_commons_title($f['page_url']) ?: kop_vimg_commons_title($f['file_url']);
    if ($commons !== '') $f['page_url'] = kop_vimg_commons_page($commons);   // one form for every Commons link (a file link alone, ?uselang=, the mobile site)
    if ($do === 'ready') {
        if ($f['page_url'] === '') throw new RuntimeException('Add the link to the page that shows the picture and its license.');
        if ($f['file_url'] === '' && $commons === '') throw new RuntimeException('Add the link to the picture file itself (right-click the picture, "Copy image address"). Not needed for Wikimedia Commons.');
        if ($f['author'] === '') throw new RuntimeException('Say who made the picture, as the page names them ("Unknown" if it says so).');
        if ($f['license'] === '') throw new RuntimeException('Give the license, as the page names it (for example CC BY-SA 4.0, or Public domain).');
        if (!kop_vimg_license_ok($f['license'])) throw new RuntimeException('We cannot use that license. It has to be public domain, CC0, CC BY or CC BY-SA (or the Pexels, Unsplash or Pixabay license); never NC, ND or "All rights reserved".');
        if ($f['alt'] === '') throw new RuntimeException('Write one line saying what the picture shows, for people who cannot see it.');
    }
    if ($do === 'not_found' && $f['note'] === '') throw new RuntimeException('Say where you looked first, so nobody searches the same places again.');
    $status = array('draft' => 'draft', 'ready' => 'ready', 'not_found' => 'not_found', 'pass' => 'passed', 'back' => 'draft')[$do];
    if ($do === 'draft' && $old && $old['status'] === 'sent_back') $status = 'sent_back';   // still waiting for their change

    global $wpdb;
    kop_vimg_ensure_tables();
    $now = current_time('mysql', true);
    $data = $f + array('title' => mb_substr((string) $row['title'], 0, 255), 'status' => $status, 'updated_at' => $now);
    if ($old) {
        $wpdb->update(kop_vimg_table(), $data, array('id' => (int) $old['id']));
    } else {
        $wpdb->insert(kop_vimg_table(), $data + array('project_id' => (int) $pid, 'item_key' => (string) $key, 'volunteer_id' => (int) $vol['id'], 'created_at' => $now));
    }
    return kop_vimg_public_pick(kop_vimg_get_pick($pid, $key, (int) $vol['id']));
}

/* ---- Approving: into the media library and onto the page ------------------------------------ */

/** What Commons says about a file: {thumb (at most 2000 px wide), license, artist} or null. Cached a day. */
function kop_vimg_commons_info($title) {
    if ($title === '' || !function_exists('wp_remote_get')) return null;
    $ck = 'kop_vimg_c_' . md5($title);
    $hit = get_transient($ck);
    if (is_array($hit)) return $hit;
    $res = wp_remote_get(add_query_arg(array('action' => 'query', 'titles' => $title, 'prop' => 'imageinfo', 'iiprop' => 'url|extmetadata',
        'iiurlwidth' => KOP_VIMG_MAX_WIDTH, 'format' => 'json', 'formatversion' => '2'), 'https://commons.wikimedia.org/w/api.php'),
        array('timeout' => 20, 'user-agent' => 'KidsOverProfits/1.0 (https://kidsoverprofits.org)'));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return null;
    $j = json_decode((string) wp_remote_retrieve_body($res), true);
    $ii = $j['query']['pages'][0]['imageinfo'][0] ?? null;
    if (!is_array($ii)) return null;
    $meta = (array) ($ii['extmetadata'] ?? array());
    $plain = function ($k) use ($meta) { return trim(html_entity_decode(wp_strip_all_tags((string) ($meta[$k]['value'] ?? '')), ENT_QUOTES, 'UTF-8')); };
    $out = array('thumb' => (string) ($ii['thumburl'] ?? ($ii['url'] ?? '')), 'license' => $plain('LicenseShortName'), 'artist' => $plain('Artist'));
    set_transient($ck, $out, DAY_IN_SECONDS);
    return $out;
}

/** "via Wikimedia Commons", "via Flickr", or the site's name. */
function kop_vimg_via($url) {
    $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    foreach (array('wikimedia.org' => 'Wikimedia Commons', 'flickr.com' => 'Flickr', 'pexels.com' => 'Pexels', 'unsplash.com' => 'Unsplash',
        'pixabay.com' => 'Pixabay', 'openverse.org' => 'Openverse', 'loc.gov' => 'the Library of Congress', 'archives.gov' => 'the National Archives') as $end => $name) {
        if ($host === $end || substr($host, -strlen($end) - 1) === '.' . $end) return 'via ' . $name;
    }
    return $host !== '' ? 'via ' . $host : '';
}

/** The caption under the picture, as the earlier rounds wrote it: "Author, License, via Wikimedia Commons". */
function kop_vimg_credit(array $pick) {
    return implode(', ', array_filter(array(trim((string) $pick['author']), trim((string) $pick['license']), kop_vimg_via((string) $pick['page_url']))));
}

/** The "Site Photos" FileBird folder (the copy holding the most files), or 0. */
function kop_vimg_folder_id() {
    global $wpdb;
    $fbv = $wpdb->prefix . 'fbv';
    $rel = $wpdb->prefix . 'fbv_attachment_folder';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fbv)) !== $fbv) return 0;
    return (int) $wpdb->get_var("SELECT f.id FROM {$fbv} f LEFT JOIN {$rel} r ON r.folder_id = f.id WHERE f.name = 'Site Photos' GROUP BY f.id ORDER BY COUNT(r.attachment_id) DESC, f.id LIMIT 1");
}

/**
 * The picture in the media library: one already imported from the same page
 * is reused; otherwise it is downloaded (Commons through its API, at most 2000
 * px wide), checked as an image by its bytes, and added with the credit line,
 * the alt text and _kop_source_url. Returns the attachment id.
 */
function kop_vimg_to_media(array $pick) {
    if ((int) $pick['attachment_id']) return (int) $pick['attachment_id'];
    $have = kop_page_images_attachment((string) $pick['page_url']);
    if ($have) return $have;
    $commons = kop_vimg_commons_title((string) $pick['page_url']);
    $src = (string) $pick['file_url'];
    if ($commons !== '') {
        $info = kop_vimg_commons_info($commons);
        if (!$info || $info['thumb'] === '') throw new RuntimeException('Wikimedia Commons did not answer for ' . $commons . '. Try again in a minute.');
        $src = $info['thumb'];
    }
    if (!preg_match('#^https://#i', $src)) throw new RuntimeException('The picture file link has to start with https://');
    $res = wp_safe_remote_get($src, array('timeout' => 45, 'limit_response_size' => 30 * 1048576, 'user-agent' => 'KidsOverProfits/1.0 (https://kidsoverprofits.org)'));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        throw new RuntimeException('The picture could not be downloaded from ' . $src . (is_wp_error($res) ? ' (' . $res->get_error_message() . ')' : ' (' . (int) wp_remote_retrieve_response_code($res) . ')') . '.');
    }
    $bytes = (string) wp_remote_retrieve_body($res);
    $size = @getimagesizefromstring($bytes);
    $types = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif');
    if (!$size || !isset($types[$size[2]])) throw new RuntimeException('That link is not a picture file (PNG, JPEG, WebP or GIF). Send it back and ask for the file link.');
    $ext = $types[$size[2]];
    $name = sanitize_file_name(mb_substr(sanitize_title($commons !== '' ? preg_replace('/\.\w+$/', '', substr($commons, 5)) : (string) $pick['title']), 0, 80) . '.' . $ext);
    $upload = wp_upload_bits($name, null, $bytes);
    if (!empty($upload['error'])) throw new RuntimeException('The picture could not go into the media library: ' . $upload['error']);
    if ($size[0] > KOP_VIMG_MAX_WIDTH && function_exists('wp_get_image_editor')) {
        $ed = wp_get_image_editor($upload['file']);
        if (!is_wp_error($ed)) { $ed->resize(KOP_VIMG_MAX_WIDTH, null); $ed->save($upload['file']); }
    }
    if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
    $title = $commons !== '' ? preg_replace('/\.\w+$/', '', substr($commons, 5)) : (string) $pick['title'];
    $att = wp_insert_attachment(array('post_mime_type' => $size['mime'], 'post_title' => $title, 'post_excerpt' => kop_vimg_credit($pick),
        'post_content' => '', 'post_status' => 'inherit'), $upload['file']);
    if (is_wp_error($att) || !$att) {
        @unlink($upload['file']);
        throw new RuntimeException('The picture could not go into the media library.');
    }
    wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $upload['file']));
    update_post_meta($att, '_wp_attachment_image_alt', (string) $pick['alt']);
    update_post_meta($att, '_kop_source_url', esc_url_raw((string) $pick['page_url']));
    update_post_meta($att, '_kop_vimg_pick', (int) $pick['id']);
    $folder = kop_vimg_folder_id();
    if ($folder) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'fbv_attachment_folder', array('folder_id' => $folder, 'attachment_id' => (int) $att), array('%d', '%d'));
    }
    return (int) $att;
}

/** Put the picture in its spot: the page's featured image, or a block after the heading. Throws when the spot is gone. */
function kop_vimg_place(array $spot, $att) {
    $page = kop_vimg_page($spot['page']);
    if (!$page) throw new RuntimeException('The page ' . $spot['page'] . ' is not published any more.');
    if ($spot['heading'] === '') {
        set_post_thumbnail((int) $page['ID'], (int) $att);
        return;
    }
    if (strpos((string) $page['post_content'], 'wp-image-' . (int) $att . '"') !== false) return;
    $content = kop_page_images_insert_after_heading((string) $page['post_content'], $spot['heading'], kop_page_images_block((int) $att));
    if ($content === null) throw new RuntimeException('The heading "' . $spot['heading'] . '" is not on the page exactly once any more.');
    $saved = kop_page_images_save((int) $page['ID'], $content);
    if (is_wp_error($saved)) throw new RuntimeException($saved->get_error_message());
}

/** Undo approve: take the picture off its spot (the media library copy stays). */
function kop_vimg_unplace(array $spot, $att) {
    $page = kop_vimg_page($spot['page']);
    if (!$page || !(int) $att) return;
    if ($spot['heading'] === '') {
        if ((int) get_post_thumbnail_id((int) $page['ID']) === (int) $att) delete_post_thumbnail((int) $page['ID']);
        return;
    }
    $re = '#\s*<!-- wp:image \{"id":' . (int) $att . ',[^}]*\} -->.*?<!-- /wp:image -->#s';
    $content = preg_replace($re, '', (string) $page['post_content'], 1, $n);
    if ($n) kop_page_images_save((int) $page['ID'], $content);
}

/** The spot a pick is for, from its project's item. */
function kop_vimg_spot_of(array $pick) {
    global $wpdb;
    kop_volp_ensure_tables();
    $detail = $wpdb->get_var($wpdb->prepare('SELECT detail FROM ' . kop_volp_items_table() . ' WHERE project_id = %d AND item_key = %s', (int) $pick['project_id'], (string) $pick['item_key']));
    $s = json_decode((string) $detail, true);
    if (!is_array($s) || empty($s['page'])) throw new RuntimeException('That spot is gone from the project.');
    return array('page' => (string) $s['page'], 'heading' => (string) ($s['heading'] ?? ''));
}

/**
 * The admin's call on a pick: 'approved' (into the media library and onto the
 * page), 'sent_back' with a note saying what to change, or 'ready' (back to
 * waiting: undoes an approve, taking the picture off the page).
 */
function kop_vimg_decide($pick_id, $status, $owner_note = '') {
    if (!in_array($status, array('approved', 'sent_back', 'ready'), true)) throw new RuntimeException('Unknown choice.');
    global $wpdb;
    kop_vimg_ensure_tables();
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_vimg_table() . ' WHERE id = %d', (int) $pick_id), ARRAY_A);
    if (!$r) throw new RuntimeException('No such pick.');
    $owner_note = trim(mb_substr((string) $owner_note, 0, 1000));
    if ($status === 'sent_back' && $owner_note === '') throw new RuntimeException('Say what to change, so they know what to do.');
    if ($status === 'approved' && $r['status'] !== 'ready') throw new RuntimeException('Only a pick waiting for review can be approved.');
    $spot = kop_vimg_spot_of($r);
    $att = (int) $r['attachment_id'];
    if ($status === 'approved') {
        $taken = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_vimg_table() . " WHERE item_key = %s AND status = 'approved' AND id <> %d", (string) $r['item_key'], (int) $r['id']));
        if ($taken) throw new RuntimeException('Another picture is approved for this spot already. Undo that one first.');
        $att = kop_vimg_to_media($r);
        kop_vimg_place($spot, $att);
    } elseif ($r['status'] === 'approved') {
        kop_vimg_unplace($spot, $att);
    }
    $u = wp_get_current_user();
    $wpdb->update(kop_vimg_table(), array('status' => $status, 'attachment_id' => $att, 'owner_note' => $status === 'sent_back' ? $owner_note : (string) $r['owner_note'],
        'decided_by' => $status === 'ready' ? '' : ($u && $u->exists() ? $u->user_login : ''), 'decided_at' => $status === 'ready' ? null : current_time('mysql', true)),
        array('id' => (int) $pick_id));
    if ($status === 'sent_back') {
        $p = kop_volp_get((int) $r['project_id']);
        if ($p && $p['status'] === 'closed') kop_volp_set_status($p['id'], 'open');
    }
    return $att;
}

/** {total, done, open (waiting for the admin), decided (approved), last} like kop_volp_progress(). */
function kop_vimg_progress(array $p) {
    global $wpdb;
    kop_vimg_ensure_tables();
    $rows = kop_volp_item_rows($p['id']);
    $picks = (array) $wpdb->get_results($wpdb->prepare('SELECT item_key, status, updated_at FROM ' . kop_vimg_table() . ' WHERE project_id = %d', (int) $p['id']), ARRAY_A);
    $by = array();
    foreach ($picks as $x) $by[$x['item_key']][] = $x;
    $out = array('total' => count($rows), 'done' => 0, 'open' => 0, 'decided' => 0, 'last' => '');
    foreach ($rows as $r) {
        $done = (int) $r['gone'] > 0;
        foreach ($by[$r['item_key']] ?? array() as $x) {
            if (in_array($x['status'], kop_vimg_done_statuses(), true)) $done = true;
            if ($x['status'] === 'ready') $out['open']++;
            if ($x['status'] === 'approved') $out['decided']++;
            if ($x['updated_at'] > $out['last']) $out['last'] = $x['updated_at'];
        }
        if ($done) $out['done']++;
    }
    return $out;
}

/* ---- REST ------------------------------------------------------------------------------------ */

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/volunteer/image', array(
        'methods' => 'POST', 'callback' => 'kop_vimg_rest_save',
        'permission_callback' => function (WP_REST_Request $req) {
            $who = kop_vol_rest_who($req);
            return is_wp_error($who) ? $who : true;
        },
    ));
});

function kop_vimg_rest_save(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        if (empty($who['vol'])) throw new RuntimeException('Admins see this page as a preview; picking pictures is for the volunteer.');
        $in = array();
        foreach (array('page_url', 'file_url', 'author', 'license', 'alt', 'note') as $k) if ($req->get_param($k) !== null) $in[$k] = wp_unslash((string) $req->get_param($k));
        $do = sanitize_key((string) $req->get_param('do'));
        $pick = kop_vimg_save($who['vol'], (int) $req->get_param('project_id'), (string) $req->get_param('key'), $do, $in);
        $words = array('draft' => 'Saved.', 'ready' => 'Sent for review. Thank you!', 'not_found' => 'Saved: you could not find one. Thank you for looking!',
            'pass' => 'Passed on.', 'back' => 'Taken back: change it and send it again.');
        return array('pick' => $pick, 'message' => $words[$do]);
    });
}

/* ---- Admin ----------------------------------------------------------------------------------- */

/** Handle a photo form post (called from kop_volp_admin_post()): [notice, error, made] or null when not ours. */
function kop_vimg_admin_post($do, array $in) {
    if ($do === 'images_make') {
        $made = kop_vimg_make_project((int) ($in['kop_vimg_who'] ?? 0), (string) ($in['kop_vimg_note'] ?? ''));
        return array('Photo project made.', '', $made);
    }
    if ($do === 'images_decide') {
        $st = sanitize_key((string) ($in['kop_vimg_status'] ?? ''));
        kop_vimg_decide((int) ($in['kop_vimg_pick'] ?? 0), $st, (string) ($in['kop_vimg_owner_note'] ?? ''));
        return array(array('approved' => 'Approved: the picture is in the media library (Site Photos) and on the page.', 'sent_back' => 'Sent back with your note. They see it on the spot.',
            'ready' => 'Put back to waiting, and taken off the page.')[$st] ?? 'Saved.', '', null);
    }
    return null;
}

/** "Photos for pages" on the Projects section. */
function kop_vimg_admin_settings($nonce, array $vols) {
    list($open, $why) = kop_vimg_open_spots();
    ?>
    <h3>Photos for pages</h3>
    <p style="max-width:760px">Spots on hub and article pages with no picture yet (seeds/image-spots.json): <strong><?php echo count($open); ?></strong> open<?php
        echo $why['claimed'] ? ', ' . (int) $why['claimed'] . ' in an open photo project' : ''; ?><?php echo $why['filled'] ? ', ' . (int) $why['filled'] . ' filled' : ''; ?>.
        A volunteer finds an openly licensed picture for each and says where it is; you approve each one on the project's page, and Approve puts it in the media library and on the page.</p>
    <?php if ($why['gone']) : ?><p class="description" style="max-width:760px">Not offered (the page or heading changed): <?php echo esc_html(implode(', ', $why['gone'])); ?></p><?php endif; ?>
    <?php if ($open) : ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;max-width:900px">
        <?php echo $nonce; ?>
        <label>For<br><select name="kop_vimg_who">
            <option value="0">Anyone with a volunteer link</option>
            <?php foreach ($vols as $v) : if (!empty($v['revoked_at'])) continue; ?>
                <option value="<?php echo (int) $v['id']; ?>"><?php echo esc_html($v['name']); ?></option>
            <?php endforeach; ?>
        </select></label>
        <label style="flex:1 1 280px">A line from you (optional)<br><input type="text" name="kop_vimg_note" maxlength="1000" style="width:100%"></label>
        <button type="submit" class="button button-primary" name="kop_volp_do" value="images_make">Make the photo project (<?php echo count($open); ?> spots)</button>
    </form>
    <?php endif;
}

/** One photo project: every spot with the picks sent for it, Approve / Send back. */
function kop_vimg_admin_project(array $p, $nonce, array $names) {
    global $wpdb;
    kop_vimg_ensure_tables();
    $rows = kop_volp_item_rows($p['id'], true);
    $picks = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_vimg_table() . ' WHERE project_id = %d ORDER BY updated_at', (int) $p['id']), ARRAY_A);
    $by = array();
    foreach ($picks as $x) $by[$x['item_key']][] = $x;
    $words = array('draft' => 'Working on it', 'ready' => 'Waiting for you', 'approved' => 'Approved, on the page', 'sent_back' => 'Sent back', 'not_found' => 'Could not find one', 'passed' => 'Passed on');
    $form = function ($x, $status, $label, $class, $with_note = false) use ($nonce, $p) {
        return '<form method="post" style="margin:0 0 6px">' . $nonce . '<input type="hidden" name="kop_volp_do" value="images_decide"><input type="hidden" name="kop_volp_id" value="' . (int) $p['id'] . '">'
            . '<input type="hidden" name="kop_vimg_pick" value="' . (int) $x['id'] . '"><input type="hidden" name="kop_vimg_status" value="' . esc_attr($status) . '">'
            . ($with_note ? '<input type="text" name="kop_vimg_owner_note" maxlength="1000" placeholder="What to change" style="width:100%;margin-bottom:4px" required>' : '')
            . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    };
    ?>
    <h3>Their pictures</h3>
    <p style="max-width:760px">Check that the picture fits the spot and that its page shows the license they name (for Commons files, what Commons itself says is shown too).
        Approve copies it into the media library (Site Photos, at most 2000 px wide) with the credit line as its caption and puts it on the page; Undo approve takes it off the page.</p>
    <table class="widefat striped" style="max-width:1100px">
        <thead><tr><th style="width:26%">Spot</th><th>Their pictures</th></tr></thead><tbody>
        <?php foreach ($rows as $r) :
            $s = json_decode((string) $r['detail'], true) ?: array();
            $xs = $by[$r['item_key']] ?? array();
            ?>
            <tr>
                <td><a href="<?php echo esc_url((string) ($s['page_url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($s['page_title'] ?? $r['title'])); ?></a><br>
                    <span class="description"><?php echo esc_html(($s['heading'] ?? '') !== '' ? 'After "' . $s['heading'] . '"' : 'Top of the page (featured image)'); ?></span></td>
                <td><?php if (!$xs) : ?><span class="description">Nobody has sent one yet</span><?php endif; ?>
                    <?php foreach ($xs as $x) :
                        $commons = kop_vimg_commons_title((string) $x['page_url']);
                        $info = $commons !== '' && in_array($x['status'], array('ready', 'sent_back'), true) ? kop_vimg_commons_info($commons) : null;
                        $show = (int) $x['attachment_id'] && function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url((int) $x['attachment_id']) : ($info['thumb'] ?? (string) $x['file_url']);
                        ?>
                        <div style="margin:0 0 12px">
                            <strong><?php echo esc_html($words[$x['status']] ?? $x['status']); ?></strong> · <?php echo esc_html($names[(int) $x['volunteer_id']] ?? '(gone)'); ?>
                            <?php if ($x['status'] === 'not_found') : ?>
                                <div style="white-space:pre-wrap">Where they looked: <?php echo esc_html((string) $x['note']); ?></div>
                            <?php else : ?>
                                <?php if (preg_match('#^https?://#i', $show)) : ?><a href="<?php echo esc_url((string) $x['page_url']); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url($show); ?>" alt="" referrerpolicy="no-referrer" style="display:block;max-width:320px;max-height:240px;margin:4px 0"></a><?php endif; ?>
                                <?php if ((string) $x['page_url'] !== '') : ?><a href="<?php echo esc_url((string) $x['page_url']); ?>" target="_blank" rel="noopener noreferrer">Its page</a><?php endif; ?>
                                <?php if ((string) $x['file_url'] !== '') : ?> · <a href="<?php echo esc_url((string) $x['file_url']); ?>" target="_blank" rel="noopener noreferrer">The file</a><?php endif; ?>
                                <br>Credit line: <?php echo esc_html(kop_vimg_credit($x)); ?>
                                <?php if ($info) : ?><br><span class="description">Commons says: <?php echo esc_html(($info['license'] ?: 'no license named') . ($info['artist'] !== '' ? ', by ' . $info['artist'] : '')); ?></span><?php endif; ?>
                                <?php if ((string) $x['alt'] !== '') : ?><br><span class="description">For people who cannot see it: <?php echo esc_html((string) $x['alt']); ?></span><?php endif; ?>
                                <?php if ((string) $x['note'] !== '') : ?><br><span class="description">Their note: <?php echo esc_html((string) $x['note']); ?></span><?php endif; ?>
                            <?php endif; ?>
                            <?php if ((string) $x['owner_note'] !== '' && $x['status'] !== 'approved') : ?><br><span class="description">Your note: <?php echo esc_html((string) $x['owner_note']); ?></span><?php endif; ?>
                            <?php
                            if ($x['status'] === 'ready') echo $form($x, 'approved', 'Approve', 'button button-primary') . $form($x, 'sent_back', 'Send back', 'button', true);
                            elseif ($x['status'] === 'approved') echo $form($x, 'ready', 'Undo approve', 'button-link');
                            ?>
                        </div>
                    <?php endforeach; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
