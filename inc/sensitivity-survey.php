<?php
/**
 * Sensitivity reader survey (2026-10-25). The "Be a sensitivity reader"
 * section of /volunteer/ (templates/page-volunteer.php) asks survivors from
 * the communities we report on most (Dropdown Lists 'sensitivity-groups',
 * inc/choice-lists/statuses.php) what our coverage misses: gaps, wording
 * that hurts and nobody has dealt with, history or culture we may not know,
 * pages that got it wrong. Owner: there are no drafts to read yet, so the
 * survey comes first; readers who tick "ask me to read drafts" are the list
 * for later.
 *
 * Answers: {prefix}kop_sensitivity_survey, read only at KOP Tools >
 * Sensitivity Survey (manage_options). Nothing is published. Name and email
 * are optional; the address and browser are never stored, only hashed into a
 * day's transient for the cap (KOP_SENS_PER_DAY_EACH, KOP_SENS_PER_DAY).
 * Status new / read / deleted (a delete is kept, so Restore is its Undo);
 * every change on the screen offers Undo back to the status before.
 *
 * REST (public): POST kop/v1/volunteer/sensitivity-survey.
 * Page: js/sensitivity-survey.js, styles in css/volunteer-signup.css.
 * Tested by scripts/test-sensitivity-survey.php (kop_sens_clean(), no WordPress).
 */

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

if (!defined('KOP_SENS_DB_VERSION')) define('KOP_SENS_DB_VERSION', '1');
if (!defined('KOP_SENS_PER_DAY_EACH')) define('KOP_SENS_PER_DAY_EACH', 5);
if (!defined('KOP_SENS_PER_DAY')) define('KOP_SENS_PER_DAY', 60);
if (!defined('KOP_SENS_MAX')) define('KOP_SENS_MAX', 6000);

/** The written answers, in the order the form asks them: key => what the admin screen calls it. */
function kop_sens_questions() {
    return array(
        'gaps'    => 'What is missing from our coverage',
        'harm'    => 'Offensive or harmful, and not dealt with',
        'context' => 'History or culture we should know',
        'pages'   => 'Pages that got it wrong',
        'more'    => 'Anything else',
    );
}

function kop_sens_statuses() {
    return array('new' => 'New', 'read' => 'Read', 'deleted' => 'Deleted');
}

/**
 * Check and tidy one submission. $groups: value => label of the communities on offer.
 * Returns the row's fields; throws RuntimeException with a message for the person.
 */
function kop_sens_clean(array $in, array $groups) {
    $text = function ($v, $max) {
        $v = str_replace("\r\n", "\n", trim((string) $v));
        $v = preg_replace("/\n{3,}/", "\n\n", $v);
        return mb_substr($v, 0, $max);
    };
    $picked = array();
    foreach ((array) ($in['groups'] ?? array()) as $g) {
        $g = (string) $g;
        if (isset($groups[$g]) && !in_array($g, $picked, true)) $picked[] = $g;
    }
    $row = array(
        'groups'      => $picked,
        'group_other' => $text($in['group_other'] ?? '', 200),
        'read_later'  => !empty($in['read_later']) ? 1 : 0,
        'name'        => $text(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')), 100),
        'email'       => $text($in['email'] ?? '', 190),
    );
    $said = 0;
    foreach (array_keys(kop_sens_questions()) as $k) {
        $row[$k] = $text($in[$k] ?? '', KOP_SENS_MAX);
        $said += mb_strlen($row[$k]);
    }
    if (!$picked && $row['group_other'] === '') {
        throw new RuntimeException('Tick the community you are answering for, or write it in.');
    }
    if ($said < 10) {
        throw new RuntimeException('Answer at least one of the questions.');
    }
    if ($row['email'] !== '') {
        $ok = function_exists('is_email') ? is_email($row['email']) : filter_var($row['email'], FILTER_VALIDATE_EMAIL);
        if (!$ok) throw new RuntimeException('That email address does not look right. You can leave it empty.');
    }
    if ($row['read_later'] && $row['email'] === '') {
        throw new RuntimeException('To be asked to read drafts later, leave an email address so we can reach you.');
    }
    if (preg_match('~https?://|www\.|[<>]~i', $row['name'])) {
        throw new RuntimeException('A name cannot hold a web address.');
    }
    return $row;
}

/* ---- Table ------------------------------------------------------------------ */

function kop_sens_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_sensitivity_survey';
}

function kop_sens_install() {
    if (get_option('kop_sens_db') === KOP_SENS_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . kop_sens_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        groups_json TEXT NULL,
        group_other VARCHAR(200) NOT NULL DEFAULT '',
        gaps TEXT NULL,
        harm TEXT NULL,
        context TEXT NULL,
        pages TEXT NULL,
        more TEXT NULL,
        read_later TINYINT(1) NOT NULL DEFAULT 0,
        name VARCHAR(100) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL,
        updated_by VARCHAR(60) NOT NULL DEFAULT '',
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY status (status)
    ) " . $wpdb->get_charset_collate() . ';');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', kop_sens_table())) === kop_sens_table()) {
        update_option('kop_sens_db', KOP_SENS_DB_VERSION, false);
    }
}

/** Save one answer from the public form. Returns its id. */
function kop_sens_submit(array $in, $who) {
    $row = kop_sens_clean($in, kop_sensitivity_groups('offered'));
    $day = gmdate('Ymd');
    $mine_key = 'kop_sens_' . $day . '_' . $who;
    $all_key = 'kop_sens_' . $day;
    if ((int) get_transient($mine_key) >= KOP_SENS_PER_DAY_EACH) {
        throw new RuntimeException('This device has sent several answers today. Please write to us on the Contact page instead.');
    }
    if ((int) get_transient($all_key) >= KOP_SENS_PER_DAY) {
        throw new RuntimeException('A lot of people answered today. Please try again tomorrow.');
    }
    kop_sens_install();
    global $wpdb;
    $data = array_diff_key($row, array('groups' => 1)) + array('groups_json' => wp_json_encode($row['groups']), 'status' => 'new', 'created_at' => current_time('mysql', true));
    if (!$wpdb->insert(kop_sens_table(), $data)) {
        throw new RuntimeException('Your answers could not be saved. Please try again.');
    }
    set_transient($mine_key, (int) get_transient($mine_key) + 1, DAY_IN_SECONDS);
    set_transient($all_key, (int) get_transient($all_key) + 1, DAY_IN_SECONDS);
    return (int) $wpdb->insert_id;
}

if (function_exists('add_action')) {
    add_action('rest_api_init', function () {
        register_rest_route('kop/v1', '/volunteer/sensitivity-survey', array(
            'methods' => 'POST', 'permission_callback' => '__return_true',
            'callback' => function (WP_REST_Request $req) {
                nocache_headers();
                $in = wp_unslash((array) $req->get_json_params());
                // Bots fill in every field; people never see this one.
                if (trim((string) ($in['website'] ?? '')) !== '') {
                    return new WP_REST_Response(array('error' => 'Something went wrong. Please try again.'), 400);
                }
                try {
                    kop_sens_submit($in, function_exists('kop_vols_who') ? kop_vols_who() : 'all');
                } catch (RuntimeException $e) {
                    return new WP_REST_Response(array('error' => $e->getMessage()), 400);
                }
                return new WP_REST_Response(array('ok' => true), 200);
            },
        ));
    });
}

/* ---- KOP Tools > Sensitivity Survey ------------------------------------------ */

if (function_exists('add_action')) {
    add_filter('kop_admin_kit_pages', function ($pages) {
        $pages[] = 'kop-sensitivity-survey';
        return $pages;
    });

    add_action('admin_menu', function () {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Sensitivity Survey', 'Sensitivity Survey', 'manage_options', 'kop-sensitivity-survey', 'kop_sens_admin_page');
    }, 30);

    add_action('admin_post_kop_sens_set', function () {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', 403);
        check_admin_referer('kop_sens_set');
        $id = (int) ($_POST['id'] ?? 0);
        $to = sanitize_key((string) ($_POST['status'] ?? ''));
        $view = sanitize_key((string) ($_POST['view'] ?? 'new'));
        $args = array('page' => 'kop-sensitivity-survey', 'view' => $view);
        global $wpdb;
        $was = (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . kop_sens_table() . ' WHERE id = %d', $id));
        if ($was !== '' && isset(kop_sens_statuses()[$to]) && $to !== $was) {
            $u = wp_get_current_user();
            $wpdb->update(kop_sens_table(), array('status' => $to, 'updated_by' => (string) $u->user_login, 'updated_at' => current_time('mysql', true)), array('id' => $id));
            $args += array('done' => $id, 'to' => $to, 'was' => $was);
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    });
}

/** One status button (admin-post form). */
function kop_sens_button($id, $to, $label, $view, $class = 'button') {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
        <?php wp_nonce_field('kop_sens_set'); ?>
        <input type="hidden" name="action" value="kop_sens_set">
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <input type="hidden" name="status" value="<?php echo esc_attr($to); ?>">
        <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
        <button type="submit" class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
}

function kop_sens_admin_page() {
    if (!current_user_can('manage_options')) return;
    kop_sens_install();
    global $wpdb;
    $statuses = kop_sens_statuses();
    $view = sanitize_key((string) ($_GET['view'] ?? 'new'));
    if (!isset($statuses[$view])) $view = 'new';
    $counts = array_fill_keys(array_keys($statuses), 0);
    foreach ((array) $wpdb->get_results('SELECT status, COUNT(*) n FROM ' . kop_sens_table() . ' GROUP BY status', ARRAY_A) as $c) {
        $counts[$c['status']] = (int) $c['n'];
    }
    $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_sens_table() . ' WHERE status = %s ORDER BY created_at DESC LIMIT 200', $view), ARRAY_A);
    $groups = kop_sensitivity_groups('all');
    ?>
    <div class="wrap">
        <h1>Sensitivity Survey</h1>
        <p>Answers from the &ldquo;Be a sensitivity reader&rdquo; survey on the <a href="<?php echo esc_url(home_url('/volunteer/#kop-volpage-sensitivity')); ?>">Volunteer page</a>.
            Only admins see them; nothing is published. The communities on offer are at KOP Tools &gt; Dropdown Lists (&ldquo;Sensitivity reader communities&rdquo;).</p>
        <?php
        $done = (int) ($_GET['done'] ?? 0);
        $was = sanitize_key((string) ($_GET['was'] ?? ''));
        $to = sanitize_key((string) ($_GET['to'] ?? ''));
        if ($done && isset($statuses[$was], $statuses[$to])) : ?>
            <div class="notice notice-success"><p>Answer #<?php echo (int) $done; ?> marked <?php echo esc_html(strtolower($statuses[$to])); ?>.
                <?php kop_sens_button($done, $was, 'Undo', $view, 'button-link'); ?></p></div>
        <?php endif; ?>
        <nav class="nav-tab-wrapper">
            <?php foreach ($statuses as $k => $label) : ?>
                <a class="nav-tab<?php echo $k === $view ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'kop-sensitivity-survey', 'view' => $k), admin_url('admin.php'))); ?>"><?php echo esc_html($label . ' (' . $counts[$k] . ')'); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if (!$rows) : ?>
            <p><?php echo $view === 'new' ? 'No new answers.' : 'None here.'; ?></p>
        <?php endif; ?>
        <?php foreach ($rows as $r) :
            $names = array();
            foreach ((array) json_decode((string) $r['groups_json'], true) as $g) $names[] = $groups[$g] ?? $g;
            if ($r['group_other'] !== '') $names[] = $r['group_other'];
            ?>
            <div class="card" style="max-width:56rem">
                <h2><?php echo esc_html(implode('; ', $names)); ?></h2>
                <p><?php echo esc_html(get_date_from_gmt($r['created_at'], 'j M Y, H:i')); ?>
                    &middot; <?php echo $r['name'] !== '' ? esc_html($r['name']) : 'No name'; ?>
                    <?php if ($r['email'] !== '') : ?>&middot; <a href="mailto:<?php echo esc_attr($r['email']); ?>"><?php echo esc_html($r['email']); ?></a><?php endif; ?>
                    <?php if ((int) $r['read_later']) : ?>&middot; <strong>Would read drafts later</strong><?php endif; ?></p>
                <?php foreach (kop_sens_questions() as $k => $label) :
                    if (trim((string) $r[$k]) === '') continue; ?>
                    <h3><?php echo esc_html($label); ?></h3>
                    <?php echo wpautop(esc_html($r[$k])); ?>
                <?php endforeach; ?>
                <p>
                    <?php if ($view === 'new') kop_sens_button($r['id'], 'read', 'Mark read', $view, 'button button-primary'); ?>
                    <?php if ($view === 'read') kop_sens_button($r['id'], 'new', 'Mark new', $view); ?>
                    <?php if ($view !== 'deleted') kop_sens_button($r['id'], 'deleted', 'Delete', $view, 'button-link-delete button-link'); ?>
                    <?php if ($view === 'deleted') kop_sens_button($r['id'], 'read', 'Restore', $view); ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
