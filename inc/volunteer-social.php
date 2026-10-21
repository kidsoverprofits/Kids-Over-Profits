<?php
/**
 * Social media projects: a volunteer project (inc/volunteer-projects.php)
 * whose items are post ideas, not review inbox items. The designer takes an
 * idea, makes the picture and writes the caption, and sends it for review; an
 * admin approves it or sends it back with a note. There are no AI captions
 * (the owner decided 2026-10-21 that the designer writes every one), and
 * nothing is posted from here yet (docs/plan 4.4 item 10d waits on the owner).
 *
 * The weekly roundup (kop_vsoc_make_roundup(), cron kop_vsoc_weekly on
 * Monday) reads the last 7 days of {prefix}kop_review_log and makes one card
 * per post idea (kop_vsoc_cards(), inc/volunteer-social-cards.php): the fact
 * in a line, the public page, the plain summary or a quote, a picture we
 * hold, an angle. It goes to the volunteer picked at Volunteer Reviewers >
 * Projects ("Weekly roundup goes to"); with nobody picked, no roundup is made.
 * A social project is always one person's: never "anyone with a link".
 *
 * Source 'social:roundup'. Table {prefix}kop_vol_social (one row per idea per
 * volunteer: status taken | ready | approved | sent_back | passed).
 * REST: kop/v1/volunteer/post. Tested by scripts/test-volunteer-projects.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_VSOC_DB_VERSION = '1';
const KOP_VSOC_SOURCE = 'social:roundup';
const KOP_VSOC_CAPTION_MAX = 2200;   // Instagram's cap, the shortest of the likely platforms but X

function kop_vsoc_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_vol_social';
}

function kop_vsoc_ensure_tables() {
    if (get_option('kop_vol_social_db') === KOP_VSOC_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = kop_vsoc_table();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        project_id BIGINT UNSIGNED NOT NULL,
        item_key VARCHAR(191) NOT NULL,
        volunteer_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'taken',
        caption TEXT NULL,
        image_url VARCHAR(500) NOT NULL DEFAULT '',
        note TEXT NULL,
        owner_note TEXT NULL,
        decided_by VARCHAR(60) NOT NULL DEFAULT '',
        decided_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY one_each (project_id, item_key, volunteer_id),
        KEY status (status)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('kop_vol_social_db', KOP_VSOC_DB_VERSION);
}

function kop_vsoc_is(array $p) {
    return strpos((string) $p['source'], 'social:') === 0;
}

/** The volunteer's work on an idea is done for now: sent for review, approved, or passed on. */
function kop_vsoc_done_statuses() {
    return array('ready', 'approved', 'passed');
}

function kop_vsoc_brief() {
    return array(
        'title'    => 'Post ideas',
        'question' => 'Take the ideas you want, make the picture and write the caption, then send it for review. Pass on any you would not post.',
        'yes'      => 'One clear fact, the place, and where to read more: "Utah closed Diamond Ranch Academy in 2023 after a boy died there. Read the state\'s findings at kidsoverprofits.org."',
        'no'       => 'Anything the card does not say, a guess about what happened, or a child\'s name. Findings about children keep the report\'s own words.',
    );
}

/* ---- Making the roundup ------------------------------------------------------------------ */

/**
 * Make a roundup project of the cards from the $days before $now for this
 * volunteer. Returns [id, link] or throws (nobody to send it to, nothing
 * happened that week).
 */
function kop_vsoc_make_roundup($vid, $days = 7, $now = null, $created_by = null) {
    $vid = (int) $vid;
    global $wpdb;
    kop_vol_ensure_tables();
    if (!$vid || !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . kop_vol_table() . ' WHERE id = %d AND revoked_at IS NULL', $vid))) {
        throw new RuntimeException('Pick who the roundup goes to: a social media project is always one person\'s.');
    }
    $now = $now ?? time();
    $from = gmdate('Y-m-d H:i:s', $now - (int) $days * DAY_IN_SECONDS);
    $to = gmdate('Y-m-d H:i:s', $now);
    $cards = kop_vsoc_cards($from, $to);
    if (!$cards) throw new RuntimeException('Nothing that week makes a post: no closures, lawsuits, bills, plain summaries, featured reporting or public money.');
    kop_volp_ensure_tables();
    $brief = kop_vsoc_brief();
    $title = 'Post ideas: ' . gmdate('M j', $now - (int) $days * DAY_IN_SECONDS) . ' to ' . gmdate('M j', $now - DAY_IN_SECONDS);
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $wpdb->insert(kop_volp_table(), array(
        'title' => $title, 'source' => KOP_VSOC_SOURCE, 'search' => '',
        'question' => $brief['question'], 'yes_example' => $brief['yes'], 'no_example' => $brief['no'], 'note' => '',
        'minutes' => max(15, count($cards) * 10), 'volunteer_id' => $vid, 'status' => 'open',
        'created_by' => $created_by ?? ($u && $u->exists() ? $u->user_login : 'weekly roundup'), 'created_at' => current_time('mysql', true),
    ));
    $id = (int) $wpdb->insert_id;
    foreach (array_values($cards) as $n => $c) {
        $wpdb->insert(kop_volp_items_table(), array('project_id' => $id, 'item_key' => (string) $c['key'], 'title' => mb_substr((string) $c['fact'], 0, 255),
            'status' => '', 'position' => $n, 'detail' => wp_json_encode($c)));
    }
    return array($id, kop_volp_url($id));
}

/** The volunteer the weekly roundup goes to (0 = nobody: no roundup is made). */
function kop_vsoc_designer() {
    return (int) get_option('kop_vsoc_designer', 0);
}

/** Monday's run: one roundup a week, for the designer, of the 7 days before. */
function kop_vsoc_weekly_run($now = null) {
    $now = $now ?? time();
    $vid = kop_vsoc_designer();
    $week = gmdate('Y-m-d', $now - ((int) gmdate('N', $now) - 1) * DAY_IN_SECONDS);   // this week's Monday
    if (!$vid || get_option('kop_vsoc_last_week') === $week) return null;
    update_option('kop_vsoc_last_week', $week, false);
    try {
        return kop_vsoc_make_roundup($vid, 7, $now, 'weekly roundup');
    } catch (Throwable $e) {
        return null;   // nobody to send it to, or a quiet week
    }
}

add_action('init', function () {
    if (!wp_next_scheduled('kop_vsoc_weekly')) {
        $monday = strtotime('next monday 14:00 UTC');
        wp_schedule_event($monday ?: time() + DAY_IN_SECONDS, 'weekly', 'kop_vsoc_weekly');
    }
});
add_action('kop_vsoc_weekly', function () { kop_vsoc_weekly_run(); });

/* ---- What the volunteer sees ------------------------------------------------------------------ */

function kop_vsoc_get_post($pid, $key, $vid) {
    global $wpdb;
    kop_vsoc_ensure_tables();
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_vsoc_table() . ' WHERE project_id = %d AND item_key = %s AND volunteer_id = %d', (int) $pid, (string) $key, (int) $vid), ARRAY_A);
    return $r ?: null;
}

function kop_vsoc_public_post(array $r) {
    return array('status' => (string) $r['status'], 'caption' => (string) $r['caption'], 'image_url' => (string) $r['image_url'],
        'note' => (string) $r['note'], 'owner_note' => (string) $r['owner_note']);
}

/** One idea as the page gets it: the card and the volunteer's draft. */
function kop_vsoc_item(array $p, array $row, $vid) {
    $c = json_decode((string) ($row['detail'] ?? ''), true);
    if (!is_array($c)) return null;
    $out = array('source' => 'social', 'project_id' => (int) $p['id'], 'key' => (string) $row['item_key'], 'title' => (string) $row['title'], 'post' => null);
    foreach (array('angle', 'angle_label', 'fact', 'url', 'url_label', 'summary', 'quote', 'image', 'when') as $k) $out[$k] = (string) ($c[$k] ?? '');
    if ($vid) {
        $r = kop_vsoc_get_post((int) $p['id'], $row['item_key'], $vid);
        if ($r) $out['post'] = kop_vsoc_public_post($r);
    }
    return $out;
}

/** The next ideas this volunteer has not finished: new ones, ones they took, ones sent back. */
function kop_vsoc_items(array $p, $vid, $limit, array $skip) {
    $mine = array_fill_keys(array_map('strval', $skip), true);
    if ($vid) $mine += kop_vsoc_done_keys((int) $p['id'], (int) $vid);
    $items = array();
    foreach (kop_volp_item_rows($p['id'], true) as $r) {
        if ((int) $r['gone'] || isset($mine[$r['item_key']])) continue;
        $it = kop_vsoc_item($p, $r, $vid);
        if (!$it) continue;
        $items[] = $it;
        if (count($items) >= $limit) break;
    }
    return $items;
}

/** Keys this volunteer is done with for now (sent, approved, passed). */
function kop_vsoc_done_keys($pid, $vid) {
    global $wpdb;
    kop_vsoc_ensure_tables();
    $in = "'" . implode("','", kop_vsoc_done_statuses()) . "'";
    return array_fill_keys(array_map('strval', (array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_vsoc_table()
        . " WHERE project_id = %d AND volunteer_id = %d AND status IN ($in)", (int) $pid, (int) $vid))), true);
}

/** Keys anyone has touched in a project (the sweep keeps these). */
function kop_vsoc_touched_keys($pid) {
    global $wpdb;
    kop_vsoc_ensure_tables();
    return array_fill_keys(array_map('strval', (array) $wpdb->get_col($wpdb->prepare('SELECT DISTINCT item_key FROM ' . kop_vsoc_table() . ' WHERE project_id = %d', (int) $pid))), true);
}

/**
 * The volunteer works on one idea. $do: take (start a draft), draft (save it),
 * ready (send it for review: needs a caption, and an image link unless the
 * card has a picture), pass (not this one), back (take a sent one back).
 */
function kop_vsoc_save(array $vol, $pid, $key, $do, array $in = array()) {
    $p = kop_volp_get($pid);
    if (!$p || !kop_vsoc_is($p) || !kop_volp_for_volunteer($p, (int) $vol['id'])) throw new RuntimeException('This project is finished or not yours. Thank you!');
    $row = null;
    foreach (kop_volp_item_rows($pid, true) as $r) if ($r['item_key'] === (string) $key) $row = $r;
    if (!$row) throw new RuntimeException('That idea is not in this project any more.');
    if (!in_array($do, array('take', 'draft', 'ready', 'pass', 'back'), true)) throw new RuntimeException('Unknown step.');
    kop_vol_rate_check((int) $vol['id']);
    $old = kop_vsoc_get_post($pid, $key, (int) $vol['id']);
    if ($old && $old['status'] === 'approved') throw new RuntimeException('This one is approved already.');
    if ($do === 'back' && (!$old || $old['status'] !== 'ready')) throw new RuntimeException('Only a post waiting for review can be taken back.');

    $caption = array_key_exists('caption', $in) ? trim(mb_substr(str_replace("\r\n", "\n", (string) $in['caption']), 0, KOP_VSOC_CAPTION_MAX)) : (string) ($old['caption'] ?? '');
    $image = array_key_exists('image_url', $in) ? trim(mb_substr((string) $in['image_url'], 0, 500)) : (string) ($old['image_url'] ?? '');
    if ($image !== '' && !preg_match('#^https?://\S+$#i', $image)) throw new RuntimeException('The image link should start with https://');
    $note = array_key_exists('note', $in) ? trim(mb_substr((string) $in['note'], 0, 1000)) : (string) ($old['note'] ?? '');
    if ($do === 'ready') {
        if ($caption === '') throw new RuntimeException('Write the caption first.');
        $card = json_decode((string) $row['detail'], true);
        if ($image === '' && empty($card['image'])) throw new RuntimeException('Add a link to your picture (Canva, Google Drive, anywhere we can open it).');
    }
    $status = array('take' => 'taken', 'draft' => 'taken', 'ready' => 'ready', 'pass' => 'passed', 'back' => 'taken')[$do];
    if ($do === 'draft' && $old && $old['status'] === 'sent_back') $status = 'sent_back';   // still waiting for their change

    global $wpdb;
    kop_vsoc_ensure_tables();
    $now = current_time('mysql', true);
    $data = array('title' => mb_substr((string) $row['title'], 0, 255), 'status' => $status, 'caption' => $caption, 'image_url' => $image, 'note' => $note, 'updated_at' => $now);
    if ($old) {
        $wpdb->update(kop_vsoc_table(), $data, array('id' => (int) $old['id']));
    } else {
        $wpdb->insert(kop_vsoc_table(), $data + array('project_id' => (int) $pid, 'item_key' => (string) $key, 'volunteer_id' => (int) $vol['id'], 'created_at' => $now));
    }
    return kop_vsoc_public_post(kop_vsoc_get_post($pid, $key, (int) $vol['id']));
}

/** {total, done, open (waiting for the admin), decided (approved), last} like kop_volp_progress(). */
function kop_vsoc_progress(array $p) {
    global $wpdb;
    kop_vsoc_ensure_tables();
    $rows = kop_volp_item_rows($p['id']);
    $posts = (array) $wpdb->get_results($wpdb->prepare('SELECT item_key, status, updated_at FROM ' . kop_vsoc_table() . ' WHERE project_id = %d', (int) $p['id']), ARRAY_A);
    $by = array();
    foreach ($posts as $x) $by[$x['item_key']][] = $x;
    $out = array('total' => count($rows), 'done' => 0, 'open' => 0, 'decided' => 0, 'last' => '');
    foreach ($rows as $r) {
        $xs = $by[$r['item_key']] ?? array();
        $done = (int) $r['gone'] > 0;
        foreach ($xs as $x) {
            if (in_array($x['status'], kop_vsoc_done_statuses(), true)) $done = true;
            if ($x['status'] === 'ready') $out['open']++;
            if ($x['status'] === 'approved') $out['decided']++;
            if ($x['updated_at'] > $out['last']) $out['last'] = $x['updated_at'];
        }
        if ($done) $out['done']++;
    }
    return $out;
}

/**
 * The admin's call on a post sent for review: 'approved', or 'sent_back' with
 * a note saying what to change (a closed batch opens again so they see it).
 */
function kop_vsoc_decide($post_id, $status, $owner_note = '') {
    if (!in_array($status, array('approved', 'sent_back', 'ready'), true)) throw new RuntimeException('Unknown choice.');
    global $wpdb;
    kop_vsoc_ensure_tables();
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_vsoc_table() . ' WHERE id = %d', (int) $post_id), ARRAY_A);
    if (!$r) throw new RuntimeException('No such post.');
    $owner_note = trim(mb_substr((string) $owner_note, 0, 1000));
    if ($status === 'sent_back' && $owner_note === '') throw new RuntimeException('Say what to change, so they know what to do.');
    $u = wp_get_current_user();
    $wpdb->update(kop_vsoc_table(), array('status' => $status, 'owner_note' => $status === 'sent_back' ? $owner_note : (string) $r['owner_note'],
        'decided_by' => $status === 'ready' ? '' : ($u && $u->exists() ? $u->user_login : ''), 'decided_at' => $status === 'ready' ? null : current_time('mysql', true)),
        array('id' => (int) $post_id));
    if ($status === 'sent_back') {
        $p = kop_volp_get((int) $r['project_id']);
        if ($p && $p['status'] === 'closed') kop_volp_set_status($p['id'], 'open');
    }
}

/* ---- REST ------------------------------------------------------------------------------------ */

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/volunteer/post', array(
        'methods' => 'POST', 'callback' => 'kop_vsoc_rest_post',
        'permission_callback' => function (WP_REST_Request $req) {
            $who = kop_vol_rest_who($req);
            return is_wp_error($who) ? $who : true;
        },
    ));
});

function kop_vsoc_rest_post(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        if (empty($who['vol'])) throw new RuntimeException('Admins see this page as a preview; drafting is for the volunteer.');
        $in = array();
        foreach (array('caption', 'image_url', 'note') as $k) if ($req->get_param($k) !== null) $in[$k] = wp_unslash((string) $req->get_param($k));
        $do = sanitize_key((string) $req->get_param('do'));
        $post = kop_vsoc_save($who['vol'], (int) $req->get_param('project_id'), (string) $req->get_param('key'), $do, $in);
        $words = array('take' => 'It is yours. Save a draft any time.', 'draft' => 'Draft saved.', 'ready' => 'Sent for review. Thank you!',
            'pass' => 'Passed on.', 'back' => 'Taken back: change it and send it again.');
        return array('post' => $post, 'message' => $words[$do]);
    });
}

/* ---- Admin ----------------------------------------------------------------------------------- */

/** Handle a social form post (called from kop_volp_admin_post()): [notice, error, made] or null when not ours. */
function kop_vsoc_admin_post($do, array $in) {
    if ($do === 'social_designer') {
        update_option('kop_vsoc_designer', (int) ($in['kop_vsoc_designer'] ?? 0), false);
        return array((int) ($in['kop_vsoc_designer'] ?? 0) ? 'Saved: Monday\'s roundup goes to them.' : 'Saved: no weekly roundup is made.', '', null);
    }
    if ($do === 'social_now') {
        $made = kop_vsoc_make_roundup((int) ($in['kop_vsoc_designer'] ?? kop_vsoc_designer()), 7);
        return array('Roundup made.', '', array($made[0], $made[1], ''));
    }
    if ($do === 'social_decide') {
        kop_vsoc_decide((int) ($in['kop_vsoc_post'] ?? 0), sanitize_key((string) ($in['kop_vsoc_status'] ?? '')), (string) ($in['kop_vsoc_owner_note'] ?? ''));
        return array(array('approved' => 'Approved.', 'sent_back' => 'Sent back with your note. They see it on the idea.', 'ready' => 'Put back to waiting.')[sanitize_key((string) $in['kop_vsoc_status'])] ?? 'Saved.', '', null);
    }
    return null;
}

/** "Weekly roundup goes to" on the Projects section. */
function kop_vsoc_admin_settings($nonce, array $vols) {
    $cur = kop_vsoc_designer();
    ?>
    <h3>Weekly social media roundup</h3>
    <p style="max-width:760px">Every Monday, the week's closures, lawsuits, bills, plain summaries, featured reporting and public money become post ideas
        for one person. They write the captions and make the pictures (no AI captions); you approve each one on the project's page.</p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;max-width:900px">
        <?php echo $nonce; ?>
        <label>Goes to<br><select name="kop_vsoc_designer">
            <option value="0">Nobody (no roundup)</option>
            <?php foreach ($vols as $v) : if (!empty($v['revoked_at'])) continue; ?>
                <option value="<?php echo (int) $v['id']; ?>" <?php selected($cur, (int) $v['id']); ?>><?php echo esc_html($v['name']); ?></option>
            <?php endforeach; ?>
        </select></label>
        <button type="submit" class="button button-primary" name="kop_volp_do" value="social_designer">Save</button>
        <button type="submit" class="button" name="kop_volp_do" value="social_now">Make the last 7 days' roundup now</button>
    </form>
    <?php
}

/** One social project: every idea with its draft, Approve / Send back. */
function kop_vsoc_admin_project(array $p, $nonce, array $names) {
    global $wpdb;
    kop_vsoc_ensure_tables();
    $rows = kop_volp_item_rows($p['id'], true);
    $posts = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_vsoc_table() . ' WHERE project_id = %d', (int) $p['id']), ARRAY_A);
    $by = array();
    foreach ($posts as $x) $by[$x['item_key']] = $x;
    $words = array('taken' => 'Working on it', 'ready' => 'Waiting for you', 'approved' => 'Approved', 'sent_back' => 'Sent back', 'passed' => 'Passed on');
    $form = function ($x, $status, $label, $class, $with_note = false) use ($nonce, $p) {
        return '<form method="post" style="margin:0 0 6px">' . $nonce . '<input type="hidden" name="kop_volp_do" value="social_decide"><input type="hidden" name="kop_volp_id" value="' . (int) $p['id'] . '">'
            . '<input type="hidden" name="kop_vsoc_post" value="' . (int) $x['id'] . '"><input type="hidden" name="kop_vsoc_status" value="' . esc_attr($status) . '">'
            . ($with_note ? '<input type="text" name="kop_vsoc_owner_note" maxlength="1000" placeholder="What to change" style="width:100%;margin-bottom:4px" required>' : '')
            . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    };
    ?>
    <h3>Their posts</h3>
    <p style="max-width:760px">Approve the ones ready to go out; send back any that need a change, with a note. Nothing is posted from here yet: copy the caption and
        the picture to the account.</p>
    <table class="widefat striped" style="max-width:1100px">
        <thead><tr><th style="width:30%">Idea</th><th>Their post</th><th style="width:220px"></th></tr></thead><tbody>
        <?php foreach ($rows as $r) :
            $c = json_decode((string) $r['detail'], true) ?: array();
            $x = $by[$r['item_key']] ?? null;
            ?>
            <tr>
                <td><span class="description"><?php echo esc_html((string) ($c['angle_label'] ?? '')); ?></span><br>
                    <?php echo !empty($c['url']) ? '<a href="' . esc_url($c['url']) . '" target="_blank" rel="noopener">' . esc_html((string) $c['fact']) . '</a>' : esc_html((string) ($c['fact'] ?? $r['title'])); ?></td>
                <td><?php if (!$x) : ?><span class="description">Not taken yet</span><?php else : ?>
                    <strong><?php echo esc_html($words[$x['status']] ?? $x['status']); ?></strong>
                    <?php if ((string) $x['caption'] !== '') : ?><div style="white-space:pre-wrap;margin:6px 0"><?php echo esc_html((string) $x['caption']); ?></div><?php endif; ?>
                    <?php $img = (string) $x['image_url'] !== '' ? (string) $x['image_url'] : (string) ($c['image'] ?? ''); ?>
                    <?php if ($img !== '') : ?><a href="<?php echo esc_url($img); ?>" target="_blank" rel="noopener noreferrer"><?php echo (string) $x['image_url'] !== '' ? 'Their picture' : 'The card\'s picture'; ?></a><?php endif; ?>
                    <?php if ((string) $x['note'] !== '') : ?><br><span class="description">Their note: <?php echo esc_html((string) $x['note']); ?></span><?php endif; ?>
                    <?php if ((string) $x['owner_note'] !== '' && $x['status'] !== 'approved') : ?><br><span class="description">Your note: <?php echo esc_html((string) $x['owner_note']); ?></span><?php endif; ?>
                <?php endif; ?></td>
                <td><?php
                    if ($x && $x['status'] === 'ready') echo $form($x, 'approved', 'Approve', 'button button-primary') . $form($x, 'sent_back', 'Send back', 'button', true);
                    elseif ($x && $x['status'] === 'approved') echo $form($x, 'ready', 'Undo approve', 'button-link');
                    ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

require_once __DIR__ . '/volunteer-social-cards.php';
