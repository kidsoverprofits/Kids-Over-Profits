<?php
/**
 * Contact form (2026-11-19): the /contact/ page's form, so every message to Kids Over Profits lands in one
 * list the owner tracks instead of scattering across the inbox.
 *
 * Fields: name (optional: survivors may stay anonymous), email (required), what it is about (Dropdown Lists
 * 'contact-types', inc/choice-lists/contact.php), facility or program (optional), the message, and how they
 * would like to be reached (optional: email, Signal or phone, with the Signal name or number).
 *
 * Messages: {prefix}kop_contact_messages, read only at KOP Tools > Contact Messages (manage_options), filtered
 * by type and status. Statuses New, In progress, Waiting on them, Done, plus Deleted (kept, so Restore is its
 * Undo; "Erase for good" in that tab removes the row, for a sender who asks). Every status change offers Undo.
 * Nothing is published.
 *
 * Each message mails KOP_CONTACT_TO (option kop_contact_email overrides it) with the type in the subject and
 * Reply-To set to the sender. The sender gets no email at all: the page shows a plain confirmation.
 *
 * Privacy: only what the form asks is stored. No IP address, browser or referrer is kept with the message; the
 * day's cap per device counts a keyed hash of address + browser in a transient that expires after a day
 * (the same rule as the volunteer sign-up, inc/volunteer-signup.php).
 *
 * Spam: a hidden field bots fill in, a form token from GET kop/v1/contact (a WordPress nonce plus a signed
 * start time; the page is cached by LiteSpeed, so the token cannot be printed into it) that must be at least
 * KOP_CONTACT_MIN_SECONDS old, KOP_CONTACT_PER_DAY_EACH messages a day per device and KOP_CONTACT_PER_DAY in all.
 * No third-party CAPTCHA.
 *
 * Page: kop_contact_form_render(), printed by templates/page-utility.php for the 'contact' slug
 * (kop_utility_config() 'module'); js/contact-form.js; css/contact-form.css on top of css/volunteer-signup.css.
 * REST (public): GET kop/v1/contact (form token), POST kop/v1/contact (send).
 * Tested by scripts/test-contact-messages.php (kop_contact_clean(), the token, no WordPress).
 */

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

if (!defined('KOP_CONTACT_DB_VERSION')) define('KOP_CONTACT_DB_VERSION', '1');
if (!defined('KOP_CONTACT_TO')) define('KOP_CONTACT_TO', 'dani@kidsoverprofits.org');
if (!defined('KOP_CONTACT_PER_DAY_EACH')) define('KOP_CONTACT_PER_DAY_EACH', 5);
if (!defined('KOP_CONTACT_PER_DAY')) define('KOP_CONTACT_PER_DAY', 150);
if (!defined('KOP_CONTACT_MAX')) define('KOP_CONTACT_MAX', 10000);
if (!defined('KOP_CONTACT_MIN_SECONDS')) define('KOP_CONTACT_MIN_SECONDS', 3);
if (!defined('KOP_CONTACT_ADMIN_PAGE')) define('KOP_CONTACT_ADMIN_PAGE', 'kop-contact-messages');
// LiteSpeed keeps /contact/ whole: bump this when the form's markup changes so the cached copy goes once.
if (!defined('KOP_CONTACT_PAGE_VERSION')) define('KOP_CONTACT_PAGE_VERSION', '1');

/** What the message is about: value => label (Dropdown Lists 'contact-types'). */
function kop_contact_types($mode = 'all') {
    return function_exists('kop_choices') ? kop_choices('contact-types', null, $mode) : array();
}

/** Workflow states the screen acts on (left as code, like the other publication statuses). */
function kop_contact_statuses() {
    return array(
        'new'         => 'New',
        'in_progress' => 'In progress',
        'waiting'     => 'Waiting on them',
        'done'        => 'Done',
        'deleted'     => 'Deleted',
    );
}

/** How the sender would like to be reached ('' = no preference). */
function kop_contact_methods() {
    return array('email' => 'Email', 'signal' => 'Signal', 'phone' => 'Phone');
}

/** One line or a block of the sender's text: tags stripped, line ends tidied, cut to $max. */
function kop_contact_text($v, $max, $multiline = false) {
    $v = str_replace(array("\r\n", "\r"), "\n", (string) $v);
    if ($multiline) {
        $v = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($v) : trim(strip_tags($v));
        $v = preg_replace("/\n{3,}/", "\n\n", $v);
    } else {
        $v = function_exists('sanitize_text_field') ? sanitize_text_field($v) : trim(preg_replace('/\s+/u', ' ', strip_tags($v)));
    }
    return mb_substr(trim($v), 0, $max);
}

/**
 * Check and tidy one message. $types: value => label of the types on offer.
 * Returns the row's fields; throws RuntimeException with a message for the person.
 */
function kop_contact_clean(array $in, array $types) {
    $row = array(
        'type'           => (string) ($in['type'] ?? ''),
        'name'           => kop_contact_text($in['name'] ?? '', 100),
        'email'          => kop_contact_text($in['email'] ?? '', 190),
        'facility'       => kop_contact_text($in['facility'] ?? '', 200),
        'message'        => kop_contact_text($in['message'] ?? '', KOP_CONTACT_MAX, true),
        'contact_method' => (string) ($in['contact_method'] ?? ''),
        'contact_detail' => kop_contact_text($in['contact_detail'] ?? '', 100),
    );
    if (!isset($types[$row['type']])) {
        throw new RuntimeException('Choose what your message is about.');
    }
    if ($row['email'] === '') {
        throw new RuntimeException('Give an email address so we can answer you.');
    }
    $ok = function_exists('is_email') ? is_email($row['email']) : filter_var($row['email'], FILTER_VALIDATE_EMAIL);
    if (!$ok) {
        throw new RuntimeException('That email address does not look right.');
    }
    if (mb_strlen($row['message']) < 5) {
        throw new RuntimeException('Write your message.');
    }
    if (preg_match('~https?://|www\.|[<>]~i', $row['name'])) {
        throw new RuntimeException('A name cannot hold a web address.');
    }
    if ($row['contact_method'] !== '' && !isset(kop_contact_methods()[$row['contact_method']])) {
        $row['contact_method'] = '';
    }
    if ($row['contact_method'] === 'email' || $row['contact_method'] === '') {
        $row['contact_detail'] = '';
    } elseif ($row['contact_detail'] === '') {
        throw new RuntimeException($row['contact_method'] === 'signal'
            ? 'Add your Signal username or number, or choose email.'
            : 'Add the phone number to call, or choose email.');
    }
    return $row;
}

/* ---- Form token (nonce + start time) ------------------------------------------ */

function kop_contact_secret() {
    return function_exists('wp_salt') ? wp_salt('nonce') : 'kop-contact-test-secret';
}

/** "<unix time>.<signature>": when the form was opened, signed so it cannot be made up. */
function kop_contact_started_token($now = null) {
    $t = (string) (int) ($now === null ? time() : $now);
    return $t . '.' . substr(hash_hmac('sha256', 'kop-contact|' . $t, kop_contact_secret()), 0, 20);
}

/**
 * Seconds since the signed start time, or -1 when the token is missing, forged or older than a day.
 */
function kop_contact_started_age($token, $now = null) {
    $now = $now === null ? time() : (int) $now;
    if (!preg_match('/^(\d{9,11})\.([0-9a-f]{20})$/', (string) $token, $m)) return -1;
    $want = substr(hash_hmac('sha256', 'kop-contact|' . $m[1], kop_contact_secret()), 0, 20);
    if (!hash_equals($want, $m[2])) return -1;
    $age = $now - (int) $m[1];
    return ($age < 0 || $age > 86400) ? -1 : $age;
}

/** Who is sending, for the day's cap only: a keyed hash of address and browser (never stored with the message). */
function kop_contact_who() {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return substr(hash_hmac('sha256', 'kop-contact|' . $ip . '|' . $ua, kop_contact_secret()), 0, 24);
}

/* ---- Table ------------------------------------------------------------------ */

function kop_contact_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_contact_messages';
}

function kop_contact_install() {
    if (get_option('kop_contact_db') === KOP_CONTACT_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . kop_contact_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        type VARCHAR(40) NOT NULL DEFAULT '',
        name VARCHAR(100) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        facility VARCHAR(200) NOT NULL DEFAULT '',
        message MEDIUMTEXT NULL,
        contact_method VARCHAR(12) NOT NULL DEFAULT '',
        contact_detail VARCHAR(100) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL,
        updated_by VARCHAR(60) NOT NULL DEFAULT '',
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY type (type),
        KEY created_at (created_at)
    ) " . $wpdb->get_charset_collate() . ';');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', kop_contact_table())) === kop_contact_table()) {
        update_option('kop_contact_db', KOP_CONTACT_DB_VERSION, false);
    }
}

/** Save one message from the public form and mail the owner. Returns its id. */
function kop_contact_submit(array $in, $who) {
    $row = kop_contact_clean($in, kop_contact_types('offered'));
    $day = gmdate('Ymd');
    $mine_key = 'kop_contact_' . $day . '_' . $who;
    $all_key = 'kop_contact_' . $day;
    if ((int) get_transient($mine_key) >= KOP_CONTACT_PER_DAY_EACH) {
        throw new RuntimeException('This device has sent several messages today. Please email us at ' . kop_contact_to() . ' instead.');
    }
    if ((int) get_transient($all_key) >= KOP_CONTACT_PER_DAY) {
        throw new RuntimeException('We have had a lot of messages today. Please email us at ' . kop_contact_to() . ' instead.');
    }
    kop_contact_install();
    global $wpdb;
    $row += array('status' => 'new', 'created_at' => current_time('mysql', true));
    if (!$wpdb->insert(kop_contact_table(), $row)) {
        throw new RuntimeException('Your message could not be saved. Please try again, or email us at ' . kop_contact_to() . '.');
    }
    $id = (int) $wpdb->insert_id;
    set_transient($mine_key, (int) get_transient($mine_key) + 1, DAY_IN_SECONDS);
    set_transient($all_key, (int) get_transient($all_key) + 1, DAY_IN_SECONDS);
    kop_contact_notify($id, $row);
    return $id;
}

/* ---- The email ------------------------------------------------------------------ */

function kop_contact_to() {
    $to = function_exists('get_option') ? trim((string) get_option('kop_contact_email', '')) : '';
    return $to !== '' && (!function_exists('is_email') || is_email($to)) ? $to : KOP_CONTACT_TO;
}

function kop_contact_admin_url($id = 0) {
    $u = admin_url('admin.php?page=' . KOP_CONTACT_ADMIN_PAGE . '&view=all');
    return $id ? $u . '#kop-contact-' . (int) $id : $u;
}

/** Subject and body of the owner's email (plain text). */
function kop_contact_mail_parts($id, array $row, array $types) {
    $type = $types[$row['type']] ?? $row['type'];
    $type = is_array($type) ? (string) ($type['label'] ?? $row['type']) : (string) $type;
    $who = $row['name'] !== '' ? $row['name'] : 'No name given';
    $subject = '[KOP contact] ' . $type . ': ' . mb_substr($who, 0, 60);
    $lines = array('Type: ' . $type, 'Name: ' . $who, 'Email: ' . $row['email']);
    if ($row['contact_method'] !== '') {
        $m = kop_contact_methods()[$row['contact_method']] ?? $row['contact_method'];
        $lines[] = 'Prefers: ' . $m . ($row['contact_detail'] !== '' ? ' (' . $row['contact_detail'] . ')' : '');
    }
    if ($row['facility'] !== '') $lines[] = 'Facility or program: ' . $row['facility'];
    $body = implode("\n", $lines) . "\n\n" . $row['message'] . "\n\n"
        . 'Message #' . (int) $id . '. Track it at KOP Tools > Contact Messages: ' . kop_contact_admin_url($id) . "\n"
        . 'Replying to this email writes to the sender. They were sent no email.';
    return array($subject, $body);
}

function kop_contact_notify($id, array $row) {
    if (!function_exists('wp_mail')) return false;
    list($subject, $body) = kop_contact_mail_parts($id, $row, kop_contact_types('all'));
    $headers = array();
    $reply = function_exists('sanitize_email') ? sanitize_email($row['email']) : $row['email'];
    if ($reply !== '' && is_email($reply)) {
        $name = trim(preg_replace('/[\r\n"<>]+/', ' ', $row['name']));
        $headers[] = 'Reply-To: ' . ($name !== '' ? '"' . $name . '" ' : '') . '<' . $reply . '>';
    }
    return (bool) @wp_mail(kop_contact_to(), $subject, $body, $headers);
}

/* ---- REST ------------------------------------------------------------------------- */

function kop_contact_no_cache() {
    if (function_exists('nocache_headers')) nocache_headers();
    if (!headers_sent()) header('X-LiteSpeed-Cache-Control: no-cache');
}

/** GET: a fresh form token (the page itself is cached for everyone). */
function kop_contact_rest_token() {
    kop_contact_no_cache();
    return new WP_REST_Response(array('nonce' => wp_create_nonce('kop_contact'), 'started' => kop_contact_started_token()), 200);
}

/** POST: send a message. */
function kop_contact_rest_send(WP_REST_Request $req) {
    kop_contact_no_cache();
    $in = wp_unslash((array) $req->get_json_params());
    // Bots fill in every field; people never see this one.
    if (trim((string) ($in['website'] ?? '')) !== '') {
        return new WP_REST_Response(array('error' => 'Something went wrong. Please try again.'), 400);
    }
    if (!wp_verify_nonce((string) ($in['nonce'] ?? ''), 'kop_contact')) {
        return new WP_REST_Response(array('error' => 'This page was open a long time. Press Send again.', 'stale' => true), 403);
    }
    $age = kop_contact_started_age($in['started'] ?? '');
    if ($age < 0) {
        return new WP_REST_Response(array('error' => 'This page was open a long time. Press Send again.', 'stale' => true), 403);
    }
    if ($age < KOP_CONTACT_MIN_SECONDS) {
        return new WP_REST_Response(array('error' => 'That was very fast. Wait a moment and press Send again.'), 400);
    }
    try {
        kop_contact_submit($in, kop_contact_who());
    } catch (RuntimeException $e) {
        return new WP_REST_Response(array('error' => $e->getMessage()), 400);
    }
    return new WP_REST_Response(array('ok' => true), 200);
}

if (function_exists('add_action')) {
    add_action('rest_api_init', function () {
        register_rest_route('kop/v1', '/contact', array(
            array('methods' => 'GET', 'callback' => 'kop_contact_rest_token', 'permission_callback' => '__return_true'),
            array('methods' => 'POST', 'callback' => 'kop_contact_rest_send', 'permission_callback' => '__return_true'),
        ));
    });

    // The form's assets, on /contact/ only.
    add_action('wp_enqueue_scripts', function () {
        if (!is_page('contact')) return;
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        if (file_exists($dir . '/css/volunteer-signup.css')) {
            wp_enqueue_style('kop-volunteer-signup', $uri . '/css/volunteer-signup.css', array(), filemtime($dir . '/css/volunteer-signup.css'));
        }
        if (file_exists($dir . '/css/contact-form.css')) {
            wp_enqueue_style('kop-contact-form', $uri . '/css/contact-form.css', array('kop-volunteer-signup'), filemtime($dir . '/css/contact-form.css'));
        }
        if (file_exists($dir . '/js/contact-form.js')) {
            wp_enqueue_script('kop-contact-form', $uri . '/js/contact-form.js', array(), filemtime($dir . '/js/contact-form.js'), true);
        }
    }, 30);

    add_action('init', function () {
        if (get_option('kop_contact_page_version') === KOP_CONTACT_PAGE_VERSION) return;
        update_option('kop_contact_page_version', KOP_CONTACT_PAGE_VERSION, false);
        do_action('litespeed_purge_url', home_url('/contact/'));
    });
}

/* ---- The form on /contact/ ---------------------------------------------------------- */

/** An admin-editable block (inc/inline-edit.php), or the template's words when that is not loaded. */
function kop_contact_block_start($key, $label) {
    if (function_exists('kop_ie_html_start')) kop_ie_html_start($key, $label);
}
function kop_contact_block_end() {
    if (function_exists('kop_ie_html_end')) kop_ie_html_end();
}

function kop_contact_form_render() {
    $types = kop_contact_types('offered');
    $anon = home_url('/anon-submit/');
    $report = home_url('/report-abuse/');
    ?>
    <div class="kop-contact">
        <?php kop_contact_block_start('contact:intro', 'Contact page: introduction'); ?>
            <p>Questions, corrections, records requests, press inquiries, ideas for working together, or something that
                happened to you or your family: write to us here. Every message comes to one place, and we read them all.</p>
            <p>If someone is in danger right now, call 911. To report abuse at a program, see
                <a href="<?php echo esc_url($report); ?>">Where to report abuse</a>.</p>
        <?php kop_contact_block_end(); ?>

        <form class="kop-vols kop-contact-form" data-rest="<?php echo esc_url(rest_url('kop/v1/contact')); ?>" novalidate>
            <div class="kop-vols-field">
                <label for="kop-contact-type">What is this about?</label>
                <select id="kop-contact-type" name="type" required>
                    <option value="">Choose one</option>
                    <?php foreach ($types as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html(is_array($label) ? ($label['label'] ?? $value) : $label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="kop-vols-field">
                <label for="kop-contact-name">Your name <span class="kop-vols-opt">(optional)</span></label>
                <input type="text" id="kop-contact-name" name="name" maxlength="100" autocomplete="name">
                <p class="kop-vols-help">Leave it empty, or use a nickname, if you would rather not say.</p>
            </div>
            <div class="kop-vols-field">
                <label for="kop-contact-email">Email</label>
                <input type="email" id="kop-contact-email" name="email" maxlength="190" autocomplete="email" required>
                <p class="kop-vols-help">So we can answer you. It is never shown to anyone else.</p>
            </div>
            <div class="kop-vols-field">
                <label for="kop-contact-facility">Facility or program <span class="kop-vols-opt">(optional)</span></label>
                <input type="text" id="kop-contact-facility" name="facility" maxlength="200">
                <p class="kop-vols-help">If your message is about a particular place or company.</p>
            </div>
            <div class="kop-vols-field">
                <label for="kop-contact-message">Your message</label>
                <textarea id="kop-contact-message" name="message" rows="8" maxlength="<?php echo (int) KOP_CONTACT_MAX; ?>" required></textarea>
            </div>
            <fieldset class="kop-vols-field">
                <legend>How should we reach you? <span class="kop-vols-opt">(optional)</span></legend>
                <?php foreach (kop_contact_methods() as $value => $label) : ?>
                    <label class="kop-sens-check"><input type="radio" name="contact_method" value="<?php echo esc_attr($value); ?>"> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
                <div class="kop-contact-detail" hidden>
                    <label for="kop-contact-detail" class="kop-sens-sub">Signal username or phone number</label>
                    <input type="text" id="kop-contact-detail" name="contact_detail" maxlength="100" autocomplete="off">
                </div>
            </fieldset>
            <div class="kop-vols-trap" aria-hidden="true">
                <label for="kop-contact-website">Leave this empty</label>
                <input type="text" id="kop-contact-website" name="website" tabindex="-1" autocomplete="off">
            </div>
            <div class="kop-contact-privacy">
                <?php kop_contact_block_start('contact:privacy', 'Contact page: privacy note'); ?>
                    <p><strong>Who reads this:</strong> only the Kids Over Profits team. We never share your message, your
                        name or your contact details with anyone without your consent.</p>
                    <p>We keep what you type in this form and nothing else: not your IP address. You will not get an
                        automatic email from us. To send documents without giving your name, use the
                        <a href="<?php echo esc_url($anon); ?>">anonymous submission portal</a>.</p>
                <?php kop_contact_block_end(); ?>
            </div>
            <p class="kop-vols-error" role="alert" hidden></p>
            <button type="submit" class="kop-vols-btn">Send</button>
        </form>
        <div class="kop-vols kop-contact-done" hidden tabindex="-1">
            <p class="kop-vols-done-h">Thank you. Your message reached us.</p>
            <p>We read every message. If it needs an answer, we will write to you the way you asked. No automatic email has been sent.</p>
        </div>
    </div>
    <?php
}

/* ---- KOP Tools > Contact Messages ----------------------------------------------------- */

if (function_exists('add_action')) {
    add_filter('kop_admin_kit_pages', function ($pages) {
        $pages[] = KOP_CONTACT_ADMIN_PAGE;
        return $pages;
    });

    add_action('admin_menu', function () {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Contact Messages', 'Contact Messages', 'manage_options', KOP_CONTACT_ADMIN_PAGE, 'kop_contact_admin_page');
    }, 30);

    add_action('admin_post_kop_contact_set', 'kop_contact_admin_set');
    add_action('admin_post_kop_contact_erase', 'kop_contact_admin_erase');
}

/** Where to send the admin back to: the same tab, type filter and page. */
function kop_contact_back_args() {
    $args = array('page' => KOP_CONTACT_ADMIN_PAGE, 'view' => sanitize_key((string) ($_POST['view'] ?? 'all')));
    $type = sanitize_key((string) ($_POST['ftype'] ?? ''));
    if ($type !== '') $args['ftype'] = $type;
    $paged = (int) ($_POST['paged'] ?? 1);
    if ($paged > 1) $args['paged'] = $paged;
    return $args;
}

function kop_contact_admin_set() {
    if (!current_user_can('manage_options')) wp_die('Not allowed.', 403);
    check_admin_referer('kop_contact_set');
    $id = (int) ($_POST['id'] ?? 0);
    $to = sanitize_key((string) ($_POST['status'] ?? ''));
    $args = kop_contact_back_args();
    global $wpdb;
    $was = (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . kop_contact_table() . ' WHERE id = %d', $id));
    if ($was !== '' && isset(kop_contact_statuses()[$to]) && $to !== $was) {
        $u = wp_get_current_user();
        $wpdb->update(kop_contact_table(), array('status' => $to, 'updated_by' => (string) $u->user_login, 'updated_at' => current_time('mysql', true)), array('id' => $id));
        $args += array('done' => $id, 'to' => $to, 'was' => $was);
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

/** Remove a deleted message for good (a sender asked, or it is spam). Only from the Deleted tab; no Undo. */
function kop_contact_admin_erase() {
    if (!current_user_can('manage_options')) wp_die('Not allowed.', 403);
    check_admin_referer('kop_contact_erase');
    $id = (int) ($_POST['id'] ?? 0);
    $args = kop_contact_back_args();
    global $wpdb;
    if ($wpdb->delete(kop_contact_table(), array('id' => $id, 'status' => 'deleted'))) {
        $args['erased'] = $id;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

/** The hidden fields every row form carries so the screen comes back as it was. */
function kop_contact_hidden_back($view, $ftype, $paged) {
    ?>
    <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
    <input type="hidden" name="ftype" value="<?php echo esc_attr($ftype); ?>">
    <input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">
    <?php
}

/** One status button (admin-post form). */
function kop_contact_button($id, $to, $label, $view, $ftype, $paged, $class = 'button') {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
        <?php wp_nonce_field('kop_contact_set'); ?>
        <input type="hidden" name="action" value="kop_contact_set">
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <input type="hidden" name="status" value="<?php echo esc_attr($to); ?>">
        <?php kop_contact_hidden_back($view, $ftype, $paged); ?>
        <button type="submit" class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
}

function kop_contact_admin_page() {
    if (!current_user_can('manage_options')) return;
    kop_contact_install();
    global $wpdb;
    $t = kop_contact_table();
    $statuses = kop_contact_statuses();
    $types = kop_contact_types('all');
    $type_label = function ($v) use ($types) {
        $l = $types[$v] ?? $v;
        return is_array($l) ? (string) ($l['label'] ?? $v) : (string) $l;
    };
    $views = array('all' => 'All open') + $statuses;
    $view = sanitize_key((string) ($_GET['view'] ?? 'all'));
    if (!isset($views[$view])) $view = 'all';
    $ftype = sanitize_key((string) ($_GET['ftype'] ?? ''));
    if ($ftype !== '' && !isset($types[$ftype])) $ftype = '';
    $per = 50;
    $paged = max(1, (int) ($_GET['paged'] ?? 1));

    // Counts per tab, under the type filter.
    $type_sql = $ftype !== '' ? $wpdb->prepare(' AND type = %s', $ftype) : '';
    $counts = array_fill_keys(array_keys($views), 0);
    foreach ((array) $wpdb->get_results('SELECT status, COUNT(*) n FROM ' . $t . ' WHERE 1=1' . $type_sql . ' GROUP BY status', ARRAY_A) as $c) {
        if (isset($counts[$c['status']])) $counts[$c['status']] = (int) $c['n'];
        if ($c['status'] !== 'deleted') $counts['all'] += (int) $c['n'];
    }
    $where = $view === 'all' ? "status <> 'deleted'" : $wpdb->prepare('status = %s', $view);
    $total = $counts[$view];
    $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $t . ' WHERE ' . $where . $type_sql . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $per, ($paged - 1) * $per), ARRAY_A);
    $base = array('page' => KOP_CONTACT_ADMIN_PAGE);
    $link = function (array $args) use ($base) {
        return add_query_arg(array_filter($args + $base, function ($v) { return $v !== '' && $v !== null; }), admin_url('admin.php'));
    };
    ?>
    <div class="wrap">
        <h1>Contact Messages</h1>
        <p>Messages from the form on the <a href="<?php echo esc_url(home_url('/contact/')); ?>">Contact page</a>.
            Only admins see them; nothing is published, and senders get no automatic email. Each one is also mailed to
            <?php echo esc_html(kop_contact_to()); ?>. The types are at KOP Tools &gt; Dropdown Lists (&ldquo;Contact message types&rdquo;).</p>
        <?php
        $done = (int) ($_GET['done'] ?? 0);
        $was = sanitize_key((string) ($_GET['was'] ?? ''));
        $to = sanitize_key((string) ($_GET['to'] ?? ''));
        if ($done && isset($statuses[$was], $statuses[$to])) : ?>
            <div class="notice notice-success"><p>Message #<?php echo (int) $done; ?> marked <?php echo esc_html(strtolower($statuses[$to])); ?>.
                <?php kop_contact_button($done, $was, 'Undo', $view, $ftype, $paged, 'button-link'); ?></p></div>
        <?php endif; ?>
        <?php if ((int) ($_GET['erased'] ?? 0)) : ?>
            <div class="notice notice-success"><p>Message #<?php echo (int) $_GET['erased']; ?> erased for good.</p></div>
        <?php endif; ?>

        <nav class="nav-tab-wrapper">
            <?php foreach ($views as $k => $label) : ?>
                <a class="nav-tab<?php echo $k === $view ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($link(array('view' => $k, 'ftype' => $ftype))); ?>"><?php echo esc_html($label . ' (' . $counts[$k] . ')'); ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:1em 0">
            <input type="hidden" name="page" value="<?php echo esc_attr(KOP_CONTACT_ADMIN_PAGE); ?>">
            <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
            <label for="kop-contact-ftype">Type</label>
            <select id="kop-contact-ftype" name="ftype">
                <option value="">All types</option>
                <?php foreach ($types as $v => $l) : ?>
                    <option value="<?php echo esc_attr($v); ?>"<?php selected($ftype, (string) $v); ?>><?php echo esc_html($type_label($v)); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button">Filter</button>
        </form>

        <?php if (!$rows) : ?>
            <p>No messages here.</p>
        <?php endif; ?>
        <?php foreach ($rows as $r) :
            $method = kop_contact_methods()[$r['contact_method']] ?? '';
            ?>
            <div class="card" id="kop-contact-<?php echo (int) $r['id']; ?>" style="max-width:56rem">
                <h2 style="margin-bottom:.25em"><?php echo esc_html($type_label($r['type'])); ?>
                    <span style="font-weight:400;font-size:.8em">&middot; <?php echo esc_html($statuses[$r['status']] ?? $r['status']); ?></span></h2>
                <p>#<?php echo (int) $r['id']; ?> &middot; <?php echo esc_html(get_date_from_gmt($r['created_at'], 'j M Y, H:i')); ?>
                    &middot; <?php echo $r['name'] !== '' ? esc_html($r['name']) : 'No name given'; ?>
                    &middot; <a href="mailto:<?php echo esc_attr($r['email']); ?>"><?php echo esc_html($r['email']); ?></a>
                    <?php if ($method !== '') : ?>&middot; Prefers <?php echo esc_html($method); ?><?php if ($r['contact_detail'] !== '') : ?>: <?php echo esc_html($r['contact_detail']); ?><?php endif; ?><?php endif; ?></p>
                <?php if ($r['facility'] !== '') : ?>
                    <p><strong>Facility or program:</strong> <?php echo esc_html($r['facility']); ?></p>
                <?php endif; ?>
                <?php echo wpautop(esc_html((string) $r['message'])); ?>
                <?php if ($r['updated_by'] !== '' && $r['updated_at']) : ?>
                    <p class="description">Last changed by <?php echo esc_html($r['updated_by']); ?>, <?php echo esc_html(get_date_from_gmt($r['updated_at'], 'j M Y, H:i')); ?>.</p>
                <?php endif; ?>
                <div class="kop-contact-actions" style="margin:1em 0 .5em">
                    <?php if ($r['status'] !== 'deleted') : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <?php wp_nonce_field('kop_contact_set'); ?>
                            <input type="hidden" name="action" value="kop_contact_set">
                            <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                            <?php kop_contact_hidden_back($view, $ftype, $paged); ?>
                            <label class="screen-reader-text" for="kop-contact-st-<?php echo (int) $r['id']; ?>">Status</label>
                            <select id="kop-contact-st-<?php echo (int) $r['id']; ?>" name="status">
                                <?php foreach ($statuses as $k => $label) :
                                    if ($k === 'deleted') continue; ?>
                                    <option value="<?php echo esc_attr($k); ?>"<?php selected($r['status'], $k); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary">Set status</button>
                        </form>
                        <a class="button" href="mailto:<?php echo esc_attr($r['email']); ?>">Reply by email</a>
                        <?php kop_contact_button($r['id'], 'deleted', 'Delete', $view, $ftype, $paged, 'button-link-delete button-link'); ?>
                    <?php else : ?>
                        <?php kop_contact_button($r['id'], 'new', 'Restore', $view, $ftype, $paged); ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
                              onsubmit="return window.confirm('Erase message #<?php echo (int) $r['id']; ?> for good? This cannot be undone.');">
                            <?php wp_nonce_field('kop_contact_erase'); ?>
                            <input type="hidden" name="action" value="kop_contact_erase">
                            <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                            <?php kop_contact_hidden_back($view, $ftype, $paged); ?>
                            <button type="submit" class="button-link button-link-delete">Erase for good</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($total > $per) :
            $last = (int) ceil($total / $per); ?>
            <p>
                <?php if ($paged > 1) : ?><a class="button" href="<?php echo esc_url($link(array('view' => $view, 'ftype' => $ftype, 'paged' => $paged - 1))); ?>">Newer</a><?php endif; ?>
                Page <?php echo (int) $paged; ?> of <?php echo (int) $last; ?>
                <?php if ($paged < $last) : ?><a class="button" href="<?php echo esc_url($link(array('view' => $view, 'ftype' => $ftype, 'paged' => $paged + 1))); ?>">Older</a><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}
