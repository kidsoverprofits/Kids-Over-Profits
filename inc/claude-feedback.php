<?php
/**
 * "Tell Claude": the owner's notes to Claude Code, written on the page they are about.
 *
 * Admins only (manage_options, the inline-edit pencils' rule). Nobody else is sent the script, the button or the
 * routes. On the front end a small "Tell Claude" button sits beside every feature's "Report a problem" flag
 * (js/bug-reporter.js stamps the feature on each flag), and a floating one sits on every page, wp-admin and the
 * KOP Tools screens included, for anything without a flag. The note goes straight into Claude's queue: no review
 * step, because the owner wrote it. js/claude-feedback.js sends the page, the feature, an element the owner
 * pointed at, any selected text and the page's recent errors with it.
 *
 * A Claude Code session runs /claude-feedback (.claude/skills/claude-feedback/SKILL.md), which reads
 *
 *   GET  /wp-json/kop/v1/claude/feedback          the waiting notes, oldest first (X-KOP-Research-Key)
 *   POST /wp-json/kop/v1/claude/feedback/<id>     {"outcome": "done"|"returned", "note": "...", "commit": "<sha>"}
 *
 * and the panel shows Claude's answer under each note (GET .../claude/feedback/mine, admin cookie + nonce).
 * The owner can take back a note still waiting (POST .../claude/feedback/<id> {"outcome": "withdrawn"}).
 * Tested by scripts/test-claude-feedback.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_CFB_DB_VERSION = '1';
const KOP_CFB_CAP = 'manage_options';

function kop_cfb_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_claude_feedback';
}

function kop_cfb_install() {
    if (get_option('kop_cfb_db') === KOP_CFB_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    dbDelta('CREATE TABLE ' . kop_cfb_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        status VARCHAR(12) NOT NULL DEFAULT 'waiting',
        message MEDIUMTEXT NOT NULL,
        page_url VARCHAR(500) NOT NULL DEFAULT '',
        page_title VARCHAR(255) NOT NULL DEFAULT '',
        feature VARCHAR(120) NOT NULL DEFAULT '',
        feature_label VARCHAR(120) NOT NULL DEFAULT '',
        target TEXT NULL,
        selection TEXT NULL,
        context MEDIUMTEXT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        closed_at DATETIME NULL,
        claude_note TEXT NULL,
        commit_sha VARCHAR(40) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY status_created (status, created_at)
    ) $c;");
    update_option('kop_cfb_db', KOP_CFB_DB_VERSION, false);
}

function kop_cfb_can() {
    return is_user_logged_in() && current_user_can(KOP_CFB_CAP);
}

/** Cuts a string to $max characters after trimming it. */
function kop_cfb_cut($v, $max) {
    return mb_substr(trim((string) $v), 0, $max);
}

/** Text that came from the page as JSON: kept only as an array, capped so nothing stuffs megabytes in. */
function kop_cfb_json($v, $max = 40000) {
    if (!is_array($v)) return null;
    $json = wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return ($json === false || strlen($json) > $max) ? null : $json;
}

/**
 * Saves one note from the panel. $in is the decoded request body.
 *
 * @return int the new id
 */
function kop_cfb_add(array $in) {
    global $wpdb;
    $message = kop_cfb_cut($in['message'] ?? '', 10000);
    if ($message === '') throw new RuntimeException('Write what is bothering you first.');
    $target = $in['target'] ?? null;
    $wpdb->insert(kop_cfb_table(), array(
        'status'        => 'waiting',
        'message'       => $message,
        'page_url'      => kop_cfb_cut($in['pageUrl'] ?? '', 500),
        'page_title'    => kop_cfb_cut($in['pageTitle'] ?? '', 255),
        'feature'       => kop_cfb_cut($in['feature'] ?? '', 120),
        'feature_label' => kop_cfb_cut($in['featureLabel'] ?? '', 120),
        'target'        => kop_cfb_json($target, 8000),
        'selection'     => kop_cfb_cut($in['selection'] ?? '', 4000),
        'context'       => kop_cfb_json($in['context'] ?? null),
        'user_id'       => (int) get_current_user_id(),
        'created_at'    => gmdate('Y-m-d H:i:s'),
    ));
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) throw new RuntimeException('The note could not be saved.');
    return $id;
}

/** One note as the session and the panel see it. */
function kop_cfb_payload($r) {
    $r = (object) $r;
    return array(
        'id'            => (int) $r->id,
        'status'        => (string) $r->status,
        'message'       => (string) $r->message,
        'page_url'      => (string) $r->page_url,
        'page_title'    => (string) $r->page_title,
        'feature'       => (string) $r->feature,
        'feature_label' => (string) $r->feature_label,
        'target'        => json_decode((string) ($r->target ?? 'null'), true),
        'selection'     => (string) ($r->selection ?? ''),
        'context'       => json_decode((string) ($r->context ?? 'null'), true),
        'created_at'    => (string) $r->created_at,
        'closed_at'     => (string) ($r->closed_at ?? ''),
        'claude_note'   => (string) ($r->claude_note ?? ''),
        'commit'        => (string) ($r->commit_sha ?? ''),
    );
}

/** The waiting notes, oldest first (Claude's queue). */
function kop_cfb_queue() {
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . kop_cfb_table() . " WHERE status = 'waiting' ORDER BY created_at ASC, id ASC LIMIT 100");
    return array_map('kop_cfb_payload', (array) $rows);
}

/** The newest notes in any state, for the panel's "Your notes" list. */
function kop_cfb_recent($limit = 15) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_cfb_table() . ' ORDER BY id DESC LIMIT %d', (int) $limit));
    return array_map(function ($r) {
        $p = kop_cfb_payload($r);
        unset($p['context'], $p['target']);
        return $p;
    }, (array) $rows);
}

/**
 * Closes one waiting note. Claude answers 'done' or 'returned' (with a note and maybe a commit); the owner can
 * only take one back ('withdrawn').
 *
 * @return array the note as it now stands
 */
function kop_cfb_close($id, $outcome, $note = '', $commit = '', $by_owner = false) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_cfb_table() . ' WHERE id = %d', (int) $id));
    if (!$row) throw new RuntimeException('That note is gone.');
    if ((string) $row->status !== 'waiting') throw new RuntimeException('That note is not waiting any more.');
    $allowed = $by_owner ? array('withdrawn') : array('done', 'returned');
    if (!in_array($outcome, $allowed, true)) {
        throw new RuntimeException('outcome must be ' . implode(' or ', array_map(function ($o) { return '"' . $o . '"'; }, $allowed)) . '.');
    }
    $note = kop_cfb_cut(wp_strip_all_tags((string) $note), 3000);
    if (!$by_owner && $note === '') throw new RuntimeException('A note is required.');
    $commit = preg_match('/^[0-9a-f]{7,40}$/', (string) $commit) ? (string) $commit : '';
    $wpdb->update(kop_cfb_table(), array(
        'status'      => $outcome,
        'closed_at'   => gmdate('Y-m-d H:i:s'),
        'claude_note' => $by_owner ? null : $note,
        'commit_sha'  => $commit,
    ), array('id' => (int) $id));
    return kop_cfb_payload($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_cfb_table() . ' WHERE id = %d', (int) $id)));
}

/** What the script needs, printed for admins only. */
function kop_cfb_config($screen = '') {
    return array(
        'rest'    => esc_url_raw(rest_url('kop/v1/claude/feedback')),
        'nonce'   => wp_create_nonce('wp_rest'),
        'screen'  => (string) $screen,
        'commits' => 'https://github.com/kidsoverprofits/Kids-Over-Profits/commit/',
    );
}

function kop_cfb_enqueue($screen = '') {
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('kop-claude-feedback', $uri . '/css/claude-feedback.css', array(), (string) @filemtime($dir . '/css/claude-feedback.css'));
    wp_enqueue_script('kop-claude-feedback', $uri . '/js/claude-feedback.js', array(), (string) @filemtime($dir . '/js/claude-feedback.js'), true);
    wp_localize_script('kop-claude-feedback', 'KOP_CLAUDE_FEEDBACK', kop_cfb_config($screen));
}

if (function_exists('add_action')) {
    add_action('wp_enqueue_scripts', function () {
        if (is_embed() || !kop_cfb_can()) return;
        kop_cfb_enqueue();
    }, 60);

    add_action('admin_enqueue_scripts', function ($hook) {
        if (!kop_cfb_can()) return;
        kop_cfb_enqueue((string) $hook);
    }, 60);

    add_action('rest_api_init', function () {
        $is_research = function (WP_REST_Request $req) {
            return function_exists('kop_msub_is_research') && kop_msub_is_research($req);
        };
        $no_store = function ($data) {
            $resp = new WP_REST_Response($data);
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        };

        register_rest_route('kop/v1', '/claude/feedback', array(
            array(
                'methods'             => 'GET',
                'permission_callback' => $is_research,
                'callback'            => function () use ($no_store) {
                    kop_cfb_install();
                    return $no_store(array('success' => true, 'notes' => kop_cfb_queue()));
                },
            ),
            array(
                'methods'             => 'POST',
                'permission_callback' => 'kop_cfb_can',
                'callback'            => function (WP_REST_Request $req) use ($no_store) {
                    kop_cfb_install();
                    $in = $req->get_json_params();
                    try {
                        $id = kop_cfb_add(is_array($in) ? $in : array());
                    } catch (RuntimeException $e) {
                        return new WP_Error('kop_cfb', $e->getMessage(), array('status' => 400));
                    }
                    return $no_store(array('success' => true, 'id' => $id, 'recent' => kop_cfb_recent()));
                },
            ),
        ));

        register_rest_route('kop/v1', '/claude/feedback/mine', array(
            'methods'             => 'GET',
            'permission_callback' => 'kop_cfb_can',
            'callback'            => function () use ($no_store) {
                kop_cfb_install();
                return $no_store(array('success' => true, 'recent' => kop_cfb_recent()));
            },
        ));

        register_rest_route('kop/v1', '/claude/feedback/(?P<id>\d+)', array(
            'methods'             => 'POST',
            'permission_callback' => function (WP_REST_Request $req) use ($is_research) {
                return $is_research($req) || kop_cfb_can();
            },
            'callback'            => function (WP_REST_Request $req) use ($is_research, $no_store) {
                kop_cfb_install();
                $by_owner = !$is_research($req);
                try {
                    $note = kop_cfb_close((int) $req['id'], (string) $req->get_param('outcome'), (string) $req->get_param('note'), (string) $req->get_param('commit'), $by_owner);
                } catch (RuntimeException $e) {
                    return new WP_Error('kop_cfb', $e->getMessage(), array('status' => 400));
                }
                return $no_store(array('success' => true, 'note' => $note, 'recent' => $by_owner ? kop_cfb_recent() : null));
            },
        ));
    });
}
