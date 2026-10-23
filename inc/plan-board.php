<?php
/**
 * KOP Tools > Plan Board: the work plan (docs/plan/*.md) as cards, one per item, where the owner types an
 * answer or marks an item Done or Not now. A Claude session reads the open answers, folds them into the plan
 * files and says what it did; the screen then shows each answer as folded in.
 *
 * The plan reaches the server as seeds/plan-board.json (scripts/plan-board/build.py; docs/ is not deployed).
 * Answers are rows of {prefix}kop_plan_answers: changing one withdraws the old row and adds a new one, so
 * nothing typed is lost. A session never writes here: what it did with each answer is committed as
 * seeds/plan-board-passes.json ({passes: [{at, entries: [{answer, itemId, kind, result}]}]}), and an answer
 * whose id is listed there counts as folded in. Sessions read the open answers with
 * `php api/plan-board-answers.php` (read-only) or from the screen's "Copy open answers".
 * The pass is written in docs/PLAN.md, section "Plan Board".
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/admin-kit.php';

const KOP_PB_DB_VERSION = '2';

add_filter('kop_admin_kit_pages', function ($pages) {
    $pages[] = 'kop-plan-board';
    return $pages;
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Plan Board', 'Plan Board', 'manage_options', 'kop-plan-board', 'kop_pb_page');
}, 30);

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'kop-plan-board') === false) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('kop-plan-board', $uri . '/css/plan-board.css', array(), (string) @filemtime($dir . '/css/plan-board.css'));
    wp_enqueue_script('kop-plan-board', $uri . '/js/plan-board.js', array(), (string) @filemtime($dir . '/js/plan-board.js'), true);
    wp_localize_script('kop-plan-board', 'KOP_PLAN_BOARD', array(
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('kop_plan_board'),
    ));
});

function kop_pb_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_plan_answers';
}

function kop_pb_install($force = false) {
    if (!$force && get_option('kop_pb_db') === KOP_PB_DB_VERSION) return;
    global $wpdb;
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_pb_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        item_id VARCHAR(200) NOT NULL,
        item_hash VARCHAR(20) NOT NULL DEFAULT '',
        item_lead VARCHAR(255) NOT NULL DEFAULT '',
        kind VARCHAR(10) NOT NULL,
        body TEXT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        saved_at DATETIME NOT NULL,
        withdrawn_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY item_id (item_id(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Marked done only once the table is there: version 1 named a column `lead` (reserved in MySQL 8), its
    // CREATE failed and the flag was set anyway, so no answer was ever saved.
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', kop_pb_table())) === kop_pb_table()) {
        update_option('kop_pb_db', KOP_PB_DB_VERSION, false);
    } elseif ($wpdb->last_error !== '') {
        error_log('kop_pb_install: ' . $wpdb->last_error);
    }
}
add_action('admin_init', 'kop_pb_install', 5);

/** The kinds of answer and their labels. */
function kop_pb_kinds() {
    return array('answer' => 'Answered', 'done' => 'Done', 'later' => 'Not now');
}

/** seeds/plan-board.json: {built, source, sections: [{key, title, groups: [{key, title, intro, items: [{id, lead, text, ask, hash}]}]}]}.
 * "ask" is the item's one-line question for the owner (its "Ask:" line); an item with one waits on the owner. */
function kop_pb_plan($path = null) {
    static $cache = array();
    $path = $path ?? get_stylesheet_directory() . '/seeds/plan-board.json';
    if (!isset($cache[$path])) {
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        $cache[$path] = is_array($data) && isset($data['sections']) ? $data : array('built' => '', 'sections' => array());
    }
    return $cache[$path];
}

/** Every item of the plan by id, with its section, its group and where it sits ("2.4 Decisions waiting"). */
function kop_pb_items(array $plan) {
    $out = array();
    foreach ($plan['sections'] as $s) {
        foreach ($s['groups'] as $g) {
            foreach ($g['items'] as $it) {
                $it['section'] = $s['key'];
                $it['group'] = $g['key'];
                $it['where'] = kop_pb_group_label($s, $g);
                $it['ask'] = (string) ($it['ask'] ?? '');
                $out[$it['id']] = $it;
            }
        }
    }
    return $out;
}

/** A group's name: "2.4 Decisions waiting", or the section's title for a section with no subsections. */
function kop_pb_group_label(array $s, array $g) {
    if ($g['title'] === '') return $s['key'] . '. ' . preg_replace('/^Owner: .*/', 'Owner', $s['title']);
    return (preg_match('/^\d+\.\d+$/', $g['key']) ? $g['key'] . ' ' : '') . $g['title'];
}

/** seeds/plan-board-passes.json as answer id => {at, result}, plus the passes themselves (newest first). */
function kop_pb_passes($path = null) {
    $path = $path ?? get_stylesheet_directory() . '/seeds/plan-board-passes.json';
    $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
    $passes = is_array($data) && isset($data['passes']) && is_array($data['passes']) ? $data['passes'] : array();
    usort($passes, function ($a, $b) { return strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')); });
    $handled = array();
    foreach ($passes as $p) {
        foreach ((array) ($p['entries'] ?? array()) as $e) {
            $id = (int) ($e['answer'] ?? 0);
            if ($id && !isset($handled[$id])) $handled[$id] = array('at' => (string) ($p['at'] ?? ''), 'result' => (string) ($e['result'] ?? ''));
        }
    }
    return array($handled, $passes);
}

/**
 * The answers that stand (not withdrawn), newest first per item, split into open (no pass has folded them in)
 * and folded. $rows: every row of the table that is not withdrawn.
 * Returns [open: item_id => row, folded: item_id => row (the newest folded one)].
 */
function kop_pb_split(array $rows, array $handled) {
    usort($rows, function ($a, $b) { return (int) $b['id'] <=> (int) $a['id']; });
    $open = $folded = array();
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        if (isset($handled[$id])) {
            if (!isset($folded[$r['item_id']])) $folded[$r['item_id']] = $r + array('pass' => $handled[$id]);
        } elseif (!isset($open[$r['item_id']])) {
            $open[$r['item_id']] = $r;
        }
    }
    return array($open, $folded);
}

function kop_pb_rows() {
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . kop_pb_table() . ' WHERE withdrawn_at IS NULL ORDER BY id DESC', ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/** The open answers as sessions read them (api/plan-board-answers.php, "Copy open answers"). */
function kop_pb_open_export(array $open, array $items) {
    $out = array();
    foreach ($open as $item_id => $r) {
        $it = $items[$item_id] ?? null;
        $out[] = array(
            'answer' => (int) $r['id'],
            'itemId' => $item_id,
            'lead' => $r['item_lead'],
            'ask' => $it['ask'] ?? '',
            'kind' => $r['kind'],
            'text' => (string) $r['body'],
            'savedAt' => $r['saved_at'],
            'onPlan' => $it !== null,
            'itemChanged' => $it !== null && $r['item_hash'] !== '' && $r['item_hash'] !== ($it['hash'] ?? ''),
        );
    }
    usort($out, function ($a, $b) { return $a['answer'] <=> $b['answer']; });
    return $out;
}

/** The plan's inline markdown (code, bold, links, bare addresses) as HTML. */
function kop_pb_md($s) {
    $codes = array();
    $t = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = $m[1];
        return "\x00" . (count($codes) - 1) . "\x00";
    }, (string) $s);
    $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
    $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        if (preg_match('#^https?://#', $m[2])) {
            return '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
        }
        return $m[1] . ' <span class="kop-pb-path">(' . $m[2] . ')</span>';
    }, $t);
    $t = preg_replace('#(^|[\s(;])(https?://[^\s<)"]+[^\s<).,;:"])#', '$1<a href="$2" target="_blank" rel="noopener">$2</a>', $t);
    return preg_replace_callback('/\x00(\d+)\x00/', function ($m) use ($codes) {
        return '<code>' . htmlspecialchars($codes[(int) $m[1]], ENT_QUOTES, 'UTF-8') . '</code>';
    }, $t);
}

function kop_pb_when($datetime) {
    $t = strtotime((string) $datetime . ' UTC');
    return $t ? wp_date('M j, g:i a', $t) : '';
}

/**
 * One card: its question (or its lead) on top, the item's text folded under Details when it is long, then its answer
 * (saved, folded in) or the buttons to answer it. $it comes from kop_pb_items().
 */
function kop_pb_card_html(array $it, $open, $folded) {
    $kinds = kop_pb_kinds();
    $state = $open ? $open['kind'] : '';
    $ask = (string) ($it['ask'] ?? '');
    $chip = $open ? '<span class="kop-pb-chip is-' . esc_attr($state) . '">' . esc_html($kinds[$state] ?? $state) . '</span>' : '';
    $out = '<article class="kop-pb-card" id="' . esc_attr('pb-' . $it['id']) . '" data-id="' . esc_attr($it['id']) . '" data-section="' . esc_attr($it['section'] ?? '') . '"'
        . ' data-group="' . esc_attr($it['group'] ?? '') . '" data-needs="' . ($ask !== '' ? '1' : '') . '"'
        . ' data-state="' . esc_attr($state) . '" data-search="' . esc_attr(strtolower($it['id'] . ' ' . $ask . ' ' . $it['text'])) . '">';
    $out .= '<div class="kop-pb-meta"><span class="kop-pb-where">' . esc_html($it['where'] ?? '') . '</span>' . $chip . '</div>';
    $details = '<div class="kop-pb-text">' . kop_pb_md($it['text']) . '</div><p class="kop-pb-id">' . esc_html($it['id']) . '</p>';
    if ($ask !== '') {
        $out .= '<p class="kop-pb-ask">' . kop_pb_md($ask) . '</p>';
        $out .= '<details class="kop-pb-details"><summary>Details: ' . esc_html($it['lead']) . '</summary>' . $details . '</details>';
    } elseif (mb_strlen($it['text']) > 260) {
        $out .= '<p class="kop-pb-ask">' . esc_html($it['lead']) . '</p>';
        $out .= '<details class="kop-pb-details"><summary>Details</summary>' . $details . '</details>';
    } else {
        $out .= $details;
    }
    $out .= '<div class="kop-pb-answer">';
    if ($open) {
        $changed = $open['item_hash'] !== '' && $open['item_hash'] !== ($it['hash'] ?? '');
        $out .= '<div class="kop-pb-saved">' . ($open['body'] !== '' && $open['body'] !== null ? nl2br(esc_html($open['body'])) : '<em>' . esc_html($kinds[$state] ?? '') . '</em>')
            . '<span class="kop-pb-when">' . esc_html(($kinds[$state] ?? '') . ', saved ' . kop_pb_when($open['saved_at']) . '. Waiting for the next pass.')
            . ($changed ? ' The item has been reworded since.' : '') . '</span></div>';
        $out .= '<div class="kop-pb-buttons">' . kop_ak_button('Change', 'secondary', 'data-act="edit"')
            . kop_ak_button('Withdraw', 'quiet', 'data-act="withdraw"') . '</div>';
        $out .= '<div class="kop-pb-edit" hidden>' . kop_pb_form_html($it, (string) $open['body'], true) . '</div>';
    } else {
        if ($folded) {
            $out .= '<p class="kop-pb-folded">Last answer (' . esc_html($kinds[$folded['kind']] ?? '') . ', ' . esc_html(kop_pb_when($folded['saved_at'])) . ')'
                . ($folded['body'] !== '' && $folded['body'] !== null ? ': ' . esc_html(wp_trim_words((string) $folded['body'], 30)) : '')
                . '. Folded in ' . esc_html($folded['pass']['at']) . ($folded['pass']['result'] !== '' ? ': ' . kop_pb_md($folded['pass']['result']) : '') . '</p>';
        }
        // One click for Done or Not now; Answer opens the box (so does a saved draft, js/plan-board.js).
        $out .= '<div class="kop-pb-buttons kop-pb-quick">' . kop_ak_button('Answer', 'primary', 'data-act="open"')
            . kop_ak_button('Done', 'secondary', 'data-act="done"') . kop_ak_button('Not now', 'secondary', 'data-act="later"') . '</div>';
        $out .= '<div class="kop-pb-form" hidden>' . kop_pb_form_html($it, '', false) . '</div>';
    }
    $out .= '<div class="kop-pb-msg" role="status" aria-live="polite"></div></div></article>';
    return $out;
}

function kop_pb_form_html(array $it, $value, $editing) {
    $id = 'kop-pb-ta-' . md5($it['id']) . ($editing ? '-edit' : '');
    return '<label class="screen-reader-text" for="' . esc_attr($id) . '">Your answer</label>'
        . '<textarea id="' . esc_attr($id) . '" class="kop-pb-ta" rows="3" placeholder="Your answer, a decision, a correction, or what happened">' . esc_textarea($value) . '</textarea>'
        . '<div class="kop-pb-buttons">' . kop_ak_button('Save answer', 'primary', 'data-act="answer"')
        . ($editing ? kop_ak_button('Done', 'secondary', 'data-act="done"') . kop_ak_button('Not now', 'secondary', 'data-act="later"') : '')
        . kop_ak_button('Cancel', 'quiet', 'data-act="cancel"') . '<span class="kop-pb-hint">Ctrl+Enter saves</span></div>';
}

/** Saves an answer for an item: withdraws the item's standing open answer and adds this one. Returns the new row id or an error string. */
function kop_pb_save($item_id, $kind, $text, $user_id) {
    global $wpdb;
    $items = kop_pb_items(kop_pb_plan());
    if (!isset($items[$item_id])) return 'That item is no longer on the plan. Reload the page.';
    if (!isset(kop_pb_kinds()[$kind])) return 'Unknown kind of answer.';
    $text = trim((string) $text);
    if ($kind === 'answer' && $text === '') return 'Type an answer first, or use Done or Not now.';
    kop_pb_withdraw($item_id);
    $now = current_time('mysql', true);
    $ok = $wpdb->insert(kop_pb_table(), array(
        'item_id' => $item_id, 'item_hash' => (string) ($items[$item_id]['hash'] ?? ''), 'item_lead' => mb_substr((string) $items[$item_id]['lead'], 0, 255),
        'kind' => $kind, 'body' => $text, 'user_id' => (int) $user_id, 'saved_at' => $now,
    ));
    return $ok ? (int) $wpdb->insert_id : 'Could not save: ' . $wpdb->last_error;
}

/** Withdraws the item's open answers (folded-in ones stay as they are). */
function kop_pb_withdraw($item_id) {
    global $wpdb;
    list($handled) = kop_pb_passes();
    $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . kop_pb_table() . ' WHERE item_id = %s AND withdrawn_at IS NULL', $item_id));
    $open = array_values(array_filter(array_map('intval', (array) $ids), function ($id) use ($handled) { return !isset($handled[$id]); }));
    if ($open) {
        $wpdb->query($wpdb->prepare('UPDATE ' . kop_pb_table() . ' SET withdrawn_at = %s WHERE id IN (' . implode(',', $open) . ')', current_time('mysql', true)));
    }
    return count($open);
}

/** The card as it stands now, for the AJAX reply. */
function kop_pb_card_now($item_id) {
    $items = kop_pb_items(kop_pb_plan());
    if (!isset($items[$item_id])) return '';
    list($handled) = kop_pb_passes();
    list($open, $folded) = kop_pb_split(kop_pb_rows(), $handled);
    return kop_pb_card_html($items[$item_id], $open[$item_id] ?? null, $folded[$item_id] ?? null);
}

add_action('wp_ajax_kop_plan_board', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Not allowed.'), 403);
    check_ajax_referer('kop_plan_board', 'nonce');
    kop_pb_install();
    $act = sanitize_key($_POST['act'] ?? '');
    $item = sanitize_text_field(wp_unslash($_POST['item'] ?? ''));
    if ($act === 'export') {
        list($handled) = kop_pb_passes();
        list($open) = kop_pb_split(kop_pb_rows(), $handled);
        wp_send_json_success(array('answers' => kop_pb_open_export($open, kop_pb_items(kop_pb_plan()))));
    }
    if ($act === 'withdraw') {
        kop_pb_withdraw($item);
        wp_send_json_success(array('message' => 'Withdrawn.', 'html' => kop_pb_card_now($item), 'open' => kop_pb_open_count()));
    }
    if (in_array($act, array('answer', 'done', 'later'), true)) {
        $res = kop_pb_save($item, $act, wp_unslash($_POST['text'] ?? ''), get_current_user_id());
        if (!is_int($res)) wp_send_json_error(array('message' => $res));
        wp_send_json_success(array('message' => 'Saved.', 'html' => kop_pb_card_now($item), 'open' => kop_pb_open_count()));
    }
    wp_send_json_error(array('message' => 'Unknown action.'));
});

function kop_pb_open_count() {
    list($handled) = kop_pb_passes();
    list($open) = kop_pb_split(kop_pb_rows(), $handled);
    return count($open);
}

function kop_pb_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    kop_pb_install();
    $plan = kop_pb_plan();
    $items = kop_pb_items($plan);
    list($handled, $passes) = kop_pb_passes();
    list($open, $folded) = kop_pb_split(kop_pb_rows(), $handled);

    echo '<div class="wrap kop-ak kop-pb">';
    echo kop_ak_header('Plan Board', array(
        'lede' => 'The work plan as cards. <em>Needs you</em> lists only the items waiting on you, each with its question; Claude folds your answers into the plan on its next pass.',
        'steps' => array(
            'Under a question press <em>Done</em> or <em>Not now</em>, or <em>Answer</em> to type a decision, a correction or what happened (Ctrl+Enter saves). <em>Details</em> opens the full item.',
            'Say "next pass" to Claude. It reads every open answer, changes the plan to match (a done item is archived, a not-now item is parked), and does the work an answer unblocks.',
            'Once its changes are deployed, each answer shows here as folded in, with what Claude did. An answer stays yours to change or withdraw until then.',
        ),
        'help' => '<p>The cards come from the plan files in the theme repository (docs/plan/), rebuilt on every push that changes them. An item is yours when it carries a question ("Ask:" in the file); <em>Everything</em> shows the session work too. Claude never writes your answers or this screen: it reads them and commits what it did.</p>',
    ));

    $status = '<strong>' . count($items) . '</strong> items &middot; <strong class="kop-pb-open-count">' . count($open) . '</strong> answers waiting for Claude'
        . ($plan['built'] !== '' ? ' &middot; plan built ' . esc_html($plan['built']) : '');
    $tools = kop_ak_button('Copy open answers', 'secondary', '', 'kop-pb-copy');
    echo kop_ak_status($status, $tools);

    if ($passes) {
        $p = $passes[0];
        $list = '';
        foreach ((array) ($p['entries'] ?? array()) as $e) {
            $list .= '<li><strong>' . esc_html($e['lead'] ?? $e['itemId'] ?? '') . '</strong>'
                . (!empty($e['kind']) ? ' <span class="kop-pb-chip is-' . esc_attr($e['kind']) . '">' . esc_html(kop_pb_kinds()[$e['kind']] ?? $e['kind']) . '</span>' : '')
                . '<br><span class="kop-pb-result">' . kop_pb_md($e['result'] ?? '') . '</span></li>';
        }
        echo kop_ak_fold('Last pass, ' . esc_html($p['at'] ?? ''), '<ul class="kop-pb-pass">' . $list . '</ul>', true, 'kop-pb-lastpass');
    }

    $lost = array_diff_key($open, $items);
    if ($lost) {
        $list = '';
        foreach ($lost as $item_id => $r) {
            $list .= '<li><span class="kop-pb-id">' . esc_html($item_id) . '</span> (' . esc_html($r['item_lead']) . '): ' . esc_html($r['body'] !== '' ? $r['body'] : (kop_pb_kinds()[$r['kind']] ?? '')) . '</li>';
        }
        echo kop_ak_fold('Answers to items no longer on the plan (' . count($lost) . ')', '<ul class="kop-pb-pass">' . $list . '</ul><p>Claude still reads these on its next pass.</p>', true);
    }

    // The toolbar: the view (Needs you / Waiting for Claude / Everything), a section, the search box. Applied in
    // the page (js/plan-board.js), which also keeps the counts.
    $needs = count(array_filter($items, function ($it) use ($open) { return $it['ask'] !== '' && !isset($open[$it['id']]); }));
    $views = array('needs' => array('Needs you', $needs), 'waiting' => array('Waiting for Claude', count(array_intersect_key($open, $items))), 'all' => array('Everything', count($items)));
    echo '<div class="kop-pb-toolbar"><div class="kop-pb-tabs" role="toolbar" aria-label="View">';
    foreach ($views as $key => $v) {
        echo '<button type="button" class="kop-pb-tab" data-view="' . esc_attr($key) . '" aria-pressed="' . ($key === 'needs' ? 'true' : 'false') . '">'
            . esc_html($v[0]) . ' <span class="kop-pb-n" data-count="' . esc_attr($key) . '">' . (int) $v[1] . '</span></button>';
    }
    echo '</div><div class="kop-pb-row"><label class="screen-reader-text" for="kop-pb-group">Section</label><select id="kop-pb-group" class="kop-pb-group-select">'
        . '<option value="">All sections</option>';
    foreach ($plan['sections'] as $s) {
        foreach ($s['groups'] as $g) {
            if (!$g['items']) continue;
            echo '<option value="' . esc_attr($g['key']) . '">' . esc_html(kop_pb_group_label($s, $g)) . '</option>';
        }
    }
    echo '</select><input type="search" class="kop-pb-search" placeholder="Find an item" aria-label="Find an item"></div></div>';

    if (!$items) {
        echo kop_ak_empty('The plan file seeds/plan-board.json is missing or empty. Run python scripts/plan-board/build.py and deploy.');
    }
    foreach ($plan['sections'] as $s) {
        echo '<section class="kop-pb-section" data-section="' . esc_attr($s['key']) . '"><h2>' . esc_html($s['key'] . '. ' . $s['title']) . '</h2>';
        foreach ($s['groups'] as $g) {
            echo '<div class="kop-pb-group">';
            if ($g['title'] !== '') echo '<h3>' . esc_html(kop_pb_group_label($s, $g)) . '</h3>';
            foreach ($g['intro'] as $p) echo '<p class="kop-pb-intro">' . kop_pb_md($p) . '</p>';
            foreach ($g['items'] as $it) {
                echo kop_pb_card_html($items[$it['id']], $open[$it['id']] ?? null, $folded[$it['id']] ?? null);
            }
            echo '</div>';
        }
        echo '</section>';
    }
    echo '<p class="kop-pb-none" hidden>Nothing matches.</p>';
    echo '</div>';
}
