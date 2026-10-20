<?php
/**
 * Messages between volunteers and the admins: a volunteer asks a question on
 * /volunteer-review/ (about a project, one item of it, or anything), an admin
 * answers at KOP Tools > Volunteer Reviewers > Messages, and the answer shows
 * on the volunteer's page ("New reply") the next time they open it.
 *
 * One conversation per volunteer. Each message may name the project and the
 * item it is about (shown as a line above it on both sides). Volunteers have
 * no email address on file, so they are never mailed; a volunteer's message
 * mails the submission notification list (kop_submission_notify_recipients())
 * at most once every 15 minutes per volunteer, unless the option
 * kop_volm_email is '0' (the checkbox on the Messages screen).
 *
 * Table: {prefix}kop_vol_messages. REST (volunteer cookie + X-KOP-Vol, like
 * the rest of the page): kop/v1/volunteer/{messages, message}.
 * Tested by scripts/test-volunteer-projects.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_VOLM_DB_VERSION = '1';
const KOP_VOLM_HOURLY_LIMIT = 40;
const KOP_VOLM_MAX = 2000;

function kop_volm_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_vol_messages';
}

function kop_volm_ensure_tables() {
    if (get_option('kop_vol_messages_db') === KOP_VOLM_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = kop_volm_table();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        volunteer_id BIGINT UNSIGNED NOT NULL,
        project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        item_key VARCHAR(191) NOT NULL DEFAULT '',
        item_title VARCHAR(255) NOT NULL DEFAULT '',
        sender VARCHAR(10) NOT NULL,
        author VARCHAR(100) NOT NULL DEFAULT '',
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        read_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY thread (volunteer_id, id),
        KEY unread (sender, read_at)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('kop_vol_messages_db', KOP_VOLM_DB_VERSION);
}

/**
 * Add a message to a volunteer's conversation. $sender 'vol' or 'admin';
 * $ctx: project_id, item_key, item_title. Returns the new row.
 */
function kop_volm_send($vid, $sender, $body, array $ctx = array(), $author = '') {
    if (!in_array($sender, array('vol', 'admin'), true)) throw new RuntimeException('Unknown sender.');
    $body = trim(str_replace("\r\n", "\n", (string) $body));
    if ($body === '') throw new RuntimeException('Write your message first.');
    if (mb_strlen($body) > KOP_VOLM_MAX) throw new RuntimeException('That is too long: keep it under ' . number_format(KOP_VOLM_MAX) . ' characters.');
    global $wpdb;
    kop_vol_ensure_tables();
    kop_volm_ensure_tables();
    $vol = $wpdb->get_row($wpdb->prepare('SELECT id, name, revoked_at FROM ' . kop_vol_table() . ' WHERE id = %d', (int) $vid), ARRAY_A);
    if (!$vol) throw new RuntimeException('No such volunteer.');
    if ($sender === 'vol') {
        if (!empty($vol['revoked_at'])) throw new RuntimeException('Your link has been turned off.');
        $k = 'kop_volm_rate_' . (int) $vid;
        $n = (int) get_transient($k);
        if ($n >= KOP_VOLM_HOURLY_LIMIT) throw new RuntimeException('That is a lot of messages for one hour. Please wait a little.');
        set_transient($k, $n + 1, HOUR_IN_SECONDS);
    }
    $pid = (int) ($ctx['project_id'] ?? 0);
    if ($pid && (!function_exists('kop_volp_get') || !kop_volp_get($pid))) $pid = 0;
    $row = array(
        'volunteer_id' => (int) $vid,
        'project_id'   => $pid,
        'item_key'     => mb_substr(trim((string) ($ctx['item_key'] ?? '')), 0, 191),
        'item_title'   => mb_substr(trim((string) ($ctx['item_title'] ?? '')), 0, 255),
        'sender'       => $sender,
        'author'       => mb_substr($sender === 'vol' ? (string) $vol['name'] : (string) $author, 0, 100),
        'body'         => $body,
        'created_at'   => current_time('mysql', true),
    );
    $wpdb->insert(kop_volm_table(), $row);
    $row['id'] = (int) $wpdb->insert_id;
    if ($sender === 'admin') {
        // Answering reads what they wrote.
        kop_volm_mark_read($vid, 'admin');
    } else {
        kop_volm_notify($vol, $row);
    }
    return $row;
}

/** Mark the other side's messages read: $reader 'vol' reads the admins' replies, 'admin' the volunteer's messages. */
function kop_volm_mark_read($vid, $reader) {
    global $wpdb;
    kop_volm_ensure_tables();
    $wpdb->query($wpdb->prepare('UPDATE ' . kop_volm_table() . ' SET read_at = %s WHERE volunteer_id = %d AND sender = %s AND read_at IS NULL',
        current_time('mysql', true), (int) $vid, $reader === 'vol' ? 'admin' : 'vol'));
}

/** A volunteer's conversation, oldest first: rows plus 'project_title'. */
function kop_volm_thread($vid, $limit = 300) {
    global $wpdb;
    kop_volm_ensure_tables();
    $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_volm_table() . ' WHERE volunteer_id = %d ORDER BY id DESC LIMIT %d', (int) $vid, (int) $limit), ARRAY_A);
    $rows = array_reverse($rows);
    $titles = array();
    foreach ($rows as &$r) {
        $pid = (int) $r['project_id'];
        if ($pid && !isset($titles[$pid])) {
            $p = function_exists('kop_volp_get') ? kop_volp_get($pid) : null;
            $titles[$pid] = $p ? (string) $p['title'] : '';
        }
        $r['project_title'] = $pid ? $titles[$pid] : '';
    }
    unset($r);
    return $rows;
}

/** The thread as the volunteer page gets it. */
function kop_volm_public(array $rows) {
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            'id' => (int) $r['id'], 'from' => $r['sender'], 'author' => $r['sender'] === 'vol' ? 'You' : 'Kids Over Profits',
            'body' => (string) $r['body'], 'at' => $r['created_at'] . 'Z', 'project_id' => (int) $r['project_id'],
            'project' => (string) $r['project_title'], 'item' => (string) $r['item_title'],
            'unread' => $r['sender'] === 'admin' && empty($r['read_at']),
        );
    }
    return $out;
}

/** Messages from volunteers no admin has read: a count over everyone, or for one volunteer. */
function kop_volm_unread_count($vid = 0) {
    try {
        global $wpdb;
        kop_volm_ensure_tables();
        $sql = 'SELECT COUNT(*) FROM ' . kop_volm_table() . " WHERE sender = 'vol' AND read_at IS NULL";
        return (int) ($vid ? $wpdb->get_var($wpdb->prepare($sql . ' AND volunteer_id = %d', (int) $vid)) : $wpdb->get_var($sql));
    } catch (Throwable $e) {
        return 0;
    }
}

/** Admin replies this volunteer has not opened yet. */
function kop_volm_unread_for_vol($vid) {
    global $wpdb;
    kop_volm_ensure_tables();
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_volm_table() . " WHERE volunteer_id = %d AND sender = 'admin' AND read_at IS NULL", (int) $vid));
}

/** Every conversation for the admin list: [{volunteer_id, name, last (row), unread, total}], unread first, then newest. */
function kop_volm_threads() {
    global $wpdb;
    kop_vol_ensure_tables();
    kop_volm_ensure_tables();
    $m = kop_volm_table();
    $rows = (array) $wpdb->get_results("SELECT volunteer_id, COUNT(*) AS total, MAX(id) AS last_id,
            SUM(CASE WHEN sender = 'vol' AND read_at IS NULL THEN 1 ELSE 0 END) AS unread
        FROM $m GROUP BY volunteer_id", ARRAY_A);
    $out = array();
    foreach ($rows as $r) {
        $last = $wpdb->get_row($wpdb->prepare("SELECT * FROM $m WHERE id = %d", (int) $r['last_id']), ARRAY_A);
        $name = $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . kop_vol_table() . ' WHERE id = %d', (int) $r['volunteer_id']));
        $out[] = array('volunteer_id' => (int) $r['volunteer_id'], 'name' => (string) ($name ?? '(gone)'), 'last' => $last,
            'unread' => (int) $r['unread'], 'total' => (int) $r['total']);
    }
    usort($out, function ($a, $b) {
        if (($a['unread'] > 0) !== ($b['unread'] > 0)) return $a['unread'] > 0 ? -1 : 1;
        return strcmp((string) $b['last']['created_at'], (string) $a['last']['created_at']) ?: ((int) $b['last']['id'] <=> (int) $a['last']['id']);
    });
    return $out;
}

/** Messages about one project, any volunteer, oldest first. */
function kop_volm_for_project($pid) {
    global $wpdb;
    kop_vol_ensure_tables();
    kop_volm_ensure_tables();
    return (array) $wpdb->get_results($wpdb->prepare('SELECT m.*, v.name FROM ' . kop_volm_table() . ' m LEFT JOIN ' . kop_vol_table()
        . ' v ON v.id = m.volunteer_id WHERE m.project_id = %d ORDER BY m.id', (int) $pid), ARRAY_A);
}

function kop_volm_admin_url($vid = 0) {
    $u = admin_url('admin.php?page=kop-volunteer-reviewers');
    return $vid ? add_query_arg('messages', (int) $vid, $u) : $u . '#messages';
}

/** Mail the notification list about a volunteer's message (at most every 15 minutes per volunteer). */
function kop_volm_notify(array $vol, array $row) {
    if (get_option('kop_volm_email', '1') === '0' || !function_exists('wp_mail') || !function_exists('kop_submission_notify_recipients')) return false;
    $k = 'kop_volm_mailed_' . (int) $vol['id'];
    if (get_transient($k)) return false;
    $to = kop_submission_notify_recipients();
    if (!$to) return false;
    set_transient($k, 1, 15 * MINUTE_IN_SECONDS);
    $about = array();
    if ($row['project_id'] && function_exists('kop_volp_get') && ($p = kop_volp_get($row['project_id']))) $about[] = 'Project: ' . $p['title'];
    if ($row['item_title'] !== '') $about[] = 'Item: ' . $row['item_title'];
    $body = $vol['name'] . " wrote on the volunteer page:\n\n" . $row['body'] . "\n\n" . ($about ? implode("\n", $about) . "\n\n" : '')
        . 'Answer it: ' . kop_volm_admin_url((int) $vol['id']) . "\n\n"
        . 'Further messages from them in the next 15 minutes are not mailed. Turn these emails off on the Messages screen.';
    return (bool) @wp_mail($to, '[Kids Over Profits] Volunteer question from ' . $vol['name'], $body);
}

/* ---- REST (volunteer page) --------------------------------------------------------- */

add_action('rest_api_init', function () {
    foreach (array('messages' => 'kop_volm_rest_messages', 'message' => 'kop_volm_rest_message') as $path => $cb) {
        register_rest_route('kop/v1', '/volunteer/' . $path, array(
            'methods' => 'POST', 'callback' => $cb,
            'permission_callback' => function (WP_REST_Request $req) {
                $who = kop_vol_rest_who($req);
                return is_wp_error($who) ? $who : true;
            },
        ));
    }
});

/** The volunteer's conversation; 'read' => 1 marks the replies read (the page sends it once they are on screen). */
function kop_volm_rest_messages(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        if (empty($who['vol'])) return array('messages' => array(), 'unread' => 0, 'preview' => true);
        $vid = (int) $who['vol']['id'];
        $rows = kop_volm_public(kop_volm_thread($vid));
        if ($req->get_param('read')) kop_volm_mark_read($vid, 'vol');
        return array('messages' => $rows, 'unread' => count(array_filter($rows, function ($r) { return $r['unread']; })));
    });
}

function kop_volm_rest_message(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        if (empty($who['vol'])) throw new RuntimeException('Admins see this page as a preview; answer volunteers at KOP Tools > Volunteer Reviewers.');
        $vid = (int) $who['vol']['id'];
        $pid = (int) $req->get_param('project_id');
        // Only a project this volunteer can see is named on the message.
        if ($pid && (!($p = kop_volp_get($pid)) || ((int) $p['volunteer_id'] && (int) $p['volunteer_id'] !== $vid))) $pid = 0;
        kop_volm_send($vid, 'vol', wp_unslash((string) $req->get_param('body')), array(
            'project_id' => $pid, 'item_key' => (string) $req->get_param('item_key'), 'item_title' => wp_unslash((string) $req->get_param('item_title')),
        ));
        return array('messages' => kop_volm_public(kop_volm_thread($vid)), 'message' => 'Sent. The reply shows here; open this page again to see it.');
    });
}

/* ---- KOP Tools > Volunteer Reviewers: Messages ----------------------------------------- */

/** A message post on the Volunteer Reviewers screen: [notice, error] or null when not ours. */
function kop_volm_admin_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['kop_volm_do'])) return null;
    check_admin_referer('kop_vol_admin');
    $do = sanitize_key($_POST['kop_volm_do']);
    $in = wp_unslash($_POST);
    try {
        if ($do === 'reply') {
            $u = wp_get_current_user();
            kop_volm_send((int) ($in['kop_volm_vid'] ?? 0), 'admin', (string) ($in['kop_volm_body'] ?? ''), array(
                'project_id' => (int) ($in['kop_volm_project'] ?? 0),
            ), $u && $u->exists() ? ($u->display_name ?: $u->user_login) : '');
            return array('Sent. They see it the next time they open the volunteer page.', '');
        }
        if ($do === 'read') {
            kop_volm_mark_read((int) ($in['kop_volm_vid'] ?? 0), 'admin');
            return array('Marked read.', '');
        }
        if ($do === 'email') {
            update_option('kop_volm_email', empty($in['kop_volm_email']) ? '0' : '1', false);
            return array('Saved.', '');
        }
    } catch (Throwable $e) {
        return array('', $e->getMessage());
    }
    return null;
}

/** The list of conversations on the main screen. */
function kop_volm_admin_section($nonce) {
    $threads = kop_volm_threads();
    $unread = array_sum(array_column($threads, 'unread'));
    ?>
    <h2 id="messages">Messages<?php echo $unread ? ' <span class="awaiting-mod" style="vertical-align:middle">' . (int) $unread . '</span>' : ''; ?></h2>
    <p style="max-width:760px">Volunteers ask questions from their page (about a project, one item, or anything). Your answer shows on their page the next time they open it;
        they have no email address on file, so they are never mailed. To write to someone first, use <em>Message</em> in the Volunteers table.</p>
    <form method="post" style="margin:0 0 10px"><?php echo $nonce; ?><input type="hidden" name="kop_volm_do" value="email">
        <label><input type="checkbox" name="kop_volm_email" value="1" <?php checked(get_option('kop_volm_email', '1') !== '0'); ?> onchange="this.form.submit()">
            Email the submission notification list when a volunteer writes (at most every 15 minutes per person)</label></form>
    <?php
    if (!$threads) { echo '<p>None yet.</p>'; return; }
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Who</th><th>Last message</th><th>When</th><th></th></tr></thead><tbody>';
    foreach ($threads as $t) {
        $last = $t['last'];
        $from = $last['sender'] === 'admin' ? 'You: ' : '';
        echo '<tr><td><strong>' . esc_html($t['name']) . '</strong>' . ($t['unread'] ? ' <span class="awaiting-mod">' . (int) $t['unread'] . ' new</span>' : '') . '</td><td>'
            . esc_html($from . kop_rinbox_excerpt((string) $last['body'], 160)) . '</td><td>' . esc_html(get_date_from_gmt($last['created_at'], 'M j g:i a')) . '</td><td>'
            . '<a class="button' . ($t['unread'] ? ' button-primary' : '') . '" href="' . esc_url(kop_volm_admin_url($t['volunteer_id'])) . '">' . ($t['unread'] ? 'Answer' : 'Open') . '</a></td></tr>';
    }
    echo '</tbody></table>';
}

/** One volunteer's conversation with the reply box. Opening it marks their messages read. */
function kop_volm_admin_thread($vid, $nonce) {
    global $wpdb;
    kop_vol_ensure_tables();
    $vol = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_vol_table() . ' WHERE id = %d', (int) $vid), ARRAY_A);
    $base = admin_url('admin.php?page=kop-volunteer-reviewers');
    if (!$vol) { echo '<p>No such volunteer. <a href="' . esc_url($base . '#messages') . '">Back</a></p>'; return; }
    $rows = kop_volm_thread($vid);
    kop_volm_mark_read($vid, 'admin');
    $last_project = 0;
    foreach ($rows as $r) if ($r['sender'] === 'vol' && (int) $r['project_id']) $last_project = (int) $r['project_id'];
    $projects = function_exists('kop_volp_list_for') ? kop_volp_list_for((int) $vid) : array();
    ?>
    <p><a href="<?php echo esc_url($base . '#messages'); ?>">&larr; All messages</a></p>
    <h2>Messages with <?php echo esc_html($vol['name']); ?></h2>
    <?php if (!empty($vol['revoked_at'])) : ?><p><em>Their link is turned off: they cannot read a reply until you make them a new link.</em></p><?php endif; ?>
    <div style="max-width:820px">
        <?php if (!$rows) echo '<p>No messages yet. Write the first one below; it shows on their page.</p>';
        foreach ($rows as $r) :
            $mine = $r['sender'] === 'admin';
            $about = array_filter(array($r['project_title'] !== '' ? 'Project: ' . $r['project_title'] : '', $r['item_title'] !== '' ? 'Item: ' . $r['item_title'] : ''));
            ?>
            <div style="margin:0 0 10px <?php echo $mine ? '60px' : '0'; ?>;margin-right:<?php echo $mine ? '0' : '60px'; ?>;padding:10px 14px;border-radius:8px;background:<?php echo $mine ? '#eef6f7' : '#fff'; ?>;border:1px solid #c3c4c7">
                <p style="margin:0 0 4px;color:#4A5568"><strong style="color:#1d2327"><?php echo esc_html($mine ? ($r['author'] !== '' ? $r['author'] : 'You') : $vol['name']); ?></strong>
                    · <?php echo esc_html(get_date_from_gmt($r['created_at'], 'M j, Y g:i a')); ?><?php echo $mine && !empty($r['read_at']) ? ' · seen' : ''; ?></p>
                <?php if ($about) : ?><p style="margin:0 0 4px;color:#4A5568"><?php echo esc_html(implode(' · ', $about)); ?></p><?php endif; ?>
                <div style="white-space:pre-wrap"><?php echo esc_html($r['body']); ?></div>
            </div>
        <?php endforeach; ?>
        <form method="post">
            <?php echo $nonce; ?><input type="hidden" name="kop_volm_do" value="reply"><input type="hidden" name="kop_volm_vid" value="<?php echo (int) $vid; ?>">
            <p><label for="kop-volm-body"><strong>Your reply</strong></label><br>
                <textarea id="kop-volm-body" name="kop_volm_body" rows="5" maxlength="<?php echo (int) KOP_VOLM_MAX; ?>" style="width:100%" required></textarea></p>
            <?php if ($projects) : ?>
                <p><label>About (optional)<br><select name="kop_volm_project">
                    <option value="0">Nothing in particular</option>
                    <?php foreach ($projects as $p) : ?><option value="<?php echo (int) $p['id']; ?>" <?php selected($last_project, (int) $p['id']); ?>><?php echo esc_html($p['title']); ?></option><?php endforeach; ?>
                </select></label></p>
            <?php endif; ?>
            <p><button type="submit" class="button button-primary">Send</button></p>
        </form>
    </div>
    <?php
}

/** The menu's count bubble: unread volunteer messages. */
add_action('admin_menu', function () {
    global $submenu;
    $n = current_user_can('manage_options') ? kop_volm_unread_count() : 0;
    if (!$n || !is_array($submenu)) return;
    foreach ($submenu as &$items) {
        foreach ($items as &$it) {
            if (($it[2] ?? '') === 'kop-volunteer-reviewers') $it[0] .= ' <span class="awaiting-mod">' . (int) $n . '</span>';
        }
    }
}, 99);
