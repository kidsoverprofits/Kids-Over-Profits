<?php
/**
 * KOP Tools > Plan Board: the work plan (docs/plan/*.md) as cards, one per item, each one thing to do. The owner
 * picks a choice, presses Done or Not now, ticks off the steps of a job that is really a list, rewords an item
 * (Edit), takes it off the plan (Retire), marks it Needs research (Claude looks into it and brings it back with
 * sources) or hands it over (Send to Claude: Claude does it). Edits, retirements and steps show at once; a Claude session then folds
 * every open answer into the plan files and says what it did, and the screen shows each answer as folded in.
 *
 * The plan reaches the server as seeds/plan-board.json (scripts/plan-board/build.py; docs/ is not deployed).
 * Answers are rows of {prefix}kop_plan_answers keyed by what they answer: "<item id>" (answer, done, later,
 * retire, research, claude), "<item id>#<step id>" (step, skip) or "<item id>@edit" (edit, JSON {ask, why, text}). Changing one
 * withdraws the old row and adds a new one, so nothing typed is lost. A session never writes here: what it did
 * with each answer is committed as seeds/plan-board-passes.json ({passes: [{at, entries: [{answer, itemId, kind,
 * result}]}]}), and an answer whose id is listed there counts as folded in. Sessions read the open answers with
 * `php api/plan-board-answers.php` (read-only) or from the screen's "Copy open answers".
 * The pass is written in docs/PLAN.md, section "Plan Board".
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/admin-kit.php';
require_once __DIR__ . '/plan-board-checks.php';

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

/**
 * The kinds of answer and their labels. On an item: answer, done, later, retire, research (Claude researches it and
 * brings it back with what it found) and claude (Claude does it). On a step ("<item>#<step>"):
 * step (done) and skip. On "<item>@edit": edit (the owner's rewording, JSON {ask, why, text}).
 */
function kop_pb_kinds() {
    return array('answer' => 'Answered', 'done' => 'Done', 'later' => 'Not now', 'retire' => 'Retired',
        'research' => 'Needs research', 'claude' => 'Sent to Claude', 'edit' => 'Edited', 'step' => 'Step done', 'skip' => 'Step skipped');
}

/** The card kinds and their labels. */
function kop_pb_card_kinds() {
    return array('decide' => 'Decide', 'do' => 'Do', 'check' => 'Check');
}

/**
 * seeds/plan-board.json: {built, source, sections: [{key, title, groups: [{key, title, intro, items: [{id, lead, text,
 * ask, hash, date, why, go: [{label, url}], choices: [], time, kind: decide|do|check, focus, steps: [{id, label, url, note}]}]}]}]}.
 * "ask" is the item's headline for the owner (its "Ask:" line); an item with one waits on the owner.
 */
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
                $it += array('ask' => '', 'why' => '', 'go' => array(), 'choices' => array(), 'time' => '', 'focus' => false, 'steps' => array());
                $it['ask'] = (string) $it['ask'];
                if (empty($it['kind']) || !isset(kop_pb_card_kinds()[$it['kind']])) {
                    $it['kind'] = $it['choices'] ? 'decide' : ($s['key'] === '3' ? 'check' : 'do');
                }
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

/**
 * What an answer row's item_id points at: [item id, step id or '', is the edit row]. "x:a#step" is a step of x:a,
 * "x:a@edit" its rewording.
 */
function kop_pb_target($row_item_id) {
    $id = (string) $row_item_id;
    if (substr($id, -5) === '@edit') return array(substr($id, 0, -5), '', true);
    $hash = strpos($id, '#');
    if ($hash !== false) return array(substr($id, 0, $hash), substr($id, $hash + 1), false);
    return array($id, '', false);
}

/** The kinds a target takes. */
function kop_pb_kinds_for($row_item_id) {
    list(, $step, $edit) = kop_pb_target($row_item_id);
    if ($edit) return array('edit');
    if ($step !== '') return array('step', 'skip');
    return array('answer', 'done', 'later', 'retire', 'research', 'claude');
}

/** The answers that hand the item to Claude: the card shows only Undo while one stands. */
function kop_pb_handed_over($kind) {
    return in_array($kind, array('retire', 'research', 'claude'), true);
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
 * The answers that stand (not withdrawn), newest first per target (an item, a step or an item's edit), split into
 * open (no pass has folded them in) and folded. $rows: every row of the table that is not withdrawn.
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

/** The owner's open rewording of an item as {ask, why, text, row}, or null. */
function kop_pb_edit_of($item_id, array $open) {
    $r = $open[$item_id . '@edit'] ?? null;
    if (!$r) return null;
    $e = json_decode((string) $r['body'], true);
    if (!is_array($e)) return null;
    return array('ask' => (string) ($e['ask'] ?? ''), 'why' => (string) ($e['why'] ?? ''), 'text' => (string) ($e['text'] ?? ''), 'row' => $r);
}

/** The item as the owner sees it: their open rewording laid over the plan's text. */
function kop_pb_with_edit(array $it, $edit) {
    if (!$edit) return $it;
    foreach (array('ask', 'why', 'text') as $k) if ($edit[$k] !== '') $it[$k] = $edit[$k];
    $it['edited'] = $edit['row'];
    return $it;
}

/** Each step of an item with its state: '' (to do), 'step' or 'skip' (open or folded in), the row and whether it is open. */
function kop_pb_step_states(array $it, array $open, array $folded) {
    $out = array();
    foreach ((array) $it['steps'] as $st) {
        $key = $it['id'] . '#' . $st['id'];
        $r = $open[$key] ?? $folded[$key] ?? null;
        $out[] = $st + array('key' => $key, 'state' => $r ? (string) $r['kind'] : '', 'row' => $r, 'open' => isset($open[$key]));
    }
    return $out;
}

/**
 * The open answers as sessions read them (api/plan-board-answers.php, "Copy open answers"), then the items the
 * checks found done (kind "auto", answer 0, text = the evidence) that have no open answer: archive those like a Done.
 * Each entry: {answer, itemId (the item), step (its text, for a step), kind, text, edit ({ask, why, text}, for an
 * edit), lead, ask, savedAt, onPlan, itemChanged}.
 */
function kop_pb_open_export(array $open, array $items, array $auto = array()) {
    $out = array();
    foreach ($open as $row_id => $r) {
        list($item_id, $step, $is_edit) = kop_pb_target($row_id);
        $it = $items[$item_id] ?? null;
        $e = array(
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
        if ($step !== '') {
            $label = '';
            foreach ((array) ($it['steps'] ?? array()) as $st) if ($st['id'] === $step) $label = $st['label'];
            $e['step'] = $label !== '' ? $label : $step;
            $e['onPlan'] = $label !== '';
        }
        if ($is_edit) {
            $e['edit'] = json_decode((string) $r['body'], true);
            $e['text'] = '';
        }
        $out[] = $e;
    }
    usort($out, function ($a, $b) { return $a['answer'] <=> $b['answer']; });
    foreach ($auto as $item_id => $c) {
        if (isset($open[$item_id])) continue;
        $out[] = array('answer' => 0, 'itemId' => $item_id, 'lead' => $items[$item_id]['lead'] ?? '', 'ask' => $items[$item_id]['ask'] ?? '',
            'kind' => 'auto', 'text' => (string) $c['evidence'], 'savedAt' => gmdate('Y-m-d H:i:s', (int) $c['at']), 'onPlan' => true, 'itemChanged' => false);
    }
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

/** A link styled as a kit button, opening in a new tab. */
function kop_pb_link_button($label, $url, $variant = 'secondary') {
    return '<a class="button kop-ak-btn kop-ak-btn--' . esc_attr($variant) . ' kop-pb-go" href="' . esc_attr($url) . '" target="_blank" rel="noopener">'
        . esc_html($label) . ' <span aria-hidden="true">&#8599;</span></a>';
}

/**
 * One card, one thing to do: what it is (Decide / Do / Check, where, how long), the headline, why it matters, the
 * site's live count when a check reads one, the next step of a job with steps, then the buttons for its kind; Edit
 * and Retire on every card, Needs research and Send to Claude on every card not answered yet; the full item for
 * Claude folded at the bottom.
 * $it comes from kop_pb_items(); $open/$folded are the split rows (all of them: the card picks its own);
 * $check is the item's stored check result {done, evidence, at} or null.
 */
function kop_pb_card_html(array $it, array $open, array $folded, $check = null) {
    $kinds = kop_pb_kinds() + array('auto' => 'Checked: done');
    $item_open = $open[$it['id']] ?? null;
    $item_folded = $folded[$it['id']] ?? null;
    $it = kop_pb_with_edit($it, kop_pb_edit_of($it['id'], $open));
    $auto = !$item_open && $check && !empty($check['done']) && empty($check['overridden']) ? $check : null;
    $state = $item_open ? (string) $item_open['kind'] : ($auto ? 'auto' : '');
    $steps = kop_pb_step_states($it, $open, $folded);
    $todo = array_values(array_filter($steps, function ($s) { return $s['state'] === ''; }));
    $steps_open = count(array_filter($steps, function ($s) { return $s['open']; }));
    $ask = (string) $it['ask'];
    $kind = (string) $it['kind'];
    // Waiting on the owner: it has a headline, nothing answers it yet, and it has a step left (when it has steps).
    $needs = $ask !== '' && $state === '' && (!$steps || $todo);
    $waiting = $state !== '' || !empty($it['edited']) || $steps_open > 0;

    $search = strtolower($it['id'] . ' ' . $ask . ' ' . $it['why'] . ' ' . $it['text'] . ' ' . implode(' ', array_column($steps, 'label')));
    $out = '<article class="kop-pb-card" id="' . esc_attr('pb-' . $it['id']) . '" data-id="' . esc_attr($it['id']) . '" data-kind="' . esc_attr($kind) . '"'
        . ' data-section="' . esc_attr($it['section'] ?? '') . '" data-group="' . esc_attr($it['group'] ?? '') . '"'
        . ' data-needs="' . ($needs ? '1' : '') . '" data-focus="' . (!empty($it['focus']) ? '1' : '') . '" data-waiting="' . ($waiting ? '1' : '') . '"'
        . ' data-date="' . esc_attr($it['date'] ?? '') . '" data-state="' . esc_attr($state) . '" data-search="' . esc_attr($search) . '">';

    // The line above the headline.
    $new = !empty($it['date']) && $it['date'] >= wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS);
    $out .= '<div class="kop-pb-meta"><span class="kop-pb-kind is-' . esc_attr($kind) . '">' . esc_html(kop_pb_card_kinds()[$kind] ?? $kind) . '</span>'
        . ($it['time'] !== '' ? '<span class="kop-pb-time">' . esc_html($it['time']) . '</span>' : '')
        . '<span class="kop-pb-where">' . esc_html($it['where'] ?? '') . '</span>'
        . ($new ? '<span class="kop-pb-chip is-new">New</span>' : '')
        . (!empty($it['edited']) ? '<span class="kop-pb-chip is-edit">Edited</span>' : '')
        . ($state !== '' ? '<span class="kop-pb-chip is-' . esc_attr($state) . '">' . esc_html($kinds[$state] ?? $state) . '</span>' : '') . '</div>';

    $out .= '<p class="kop-pb-ask">' . ($ask !== '' ? kop_pb_md($ask) : esc_html($it['lead'])) . '</p>';
    if ($it['why'] !== '') $out .= '<p class="kop-pb-why"><span>Why:</span> ' . kop_pb_md($it['why']) . '</p>';
    if ($check && !$auto && !empty($check['evidence'])) {
        $out .= '<p class="kop-pb-now"><span>Now:</span> ' . esc_html($check['evidence']) . ' <span class="kop-pb-when-inline">(checked ' . esc_html(wp_date('M j, g:i a', (int) $check['at'])) . ')</span></p>';
    }

    // Steps: the next one up front, every step folded under it.
    if ($steps) {
        $done_n = count($steps) - count($todo);
        $out .= '<div class="kop-pb-steps"><div class="kop-pb-progress"><span class="kop-pb-bar"><span style="width:' . (int) round(100 * $done_n / count($steps)) . '%"></span></span>'
            . '<span>' . (int) $done_n . ' of ' . count($steps) . ' done</span></div>';
        if ($todo && $state === '') {
            $next = $todo[0];
            $out .= '<div class="kop-pb-next" data-step="' . esc_attr($next['id']) . '"><p class="kop-pb-next-label"><span>Next:</span> ' . kop_pb_md($next['label'])
                . ($next['note'] !== '' ? ' <span class="kop-pb-step-note">' . kop_pb_md($next['note']) . '</span>' : '') . '</p><div class="kop-pb-buttons">'
                . ($next['url'] !== '' ? kop_pb_link_button('Open', $next['url'], 'primary') : '')
                . kop_ak_button('Done, next', $next['url'] !== '' ? 'secondary' : 'primary', 'data-act="step" data-step="' . esc_attr($next['id']) . '"')
                . kop_ak_button('Skip', 'quiet', 'data-act="skip" data-step="' . esc_attr($next['id']) . '"') . '</div></div>';
        }
        $list = '';
        foreach ($steps as $st) {
            $label = $st['url'] !== '' ? '<a href="' . esc_attr($st['url']) . '" target="_blank" rel="noopener">' . kop_pb_md($st['label']) . '</a>' : kop_pb_md($st['label']);
            $btn = $st['state'] === '' ? kop_ak_button('Done', 'quiet', 'data-act="step" data-step="' . esc_attr($st['id']) . '"')
                : ($st['open'] ? kop_ak_button('Undo', 'quiet', 'data-act="unstep" data-step="' . esc_attr($st['id']) . '"') : '');
            $list .= '<li class="is-' . esc_attr($st['state'] !== '' ? $st['state'] : 'todo') . '"><span class="kop-pb-step-mark" aria-hidden="true">'
                . ($st['state'] === 'step' ? '&#10003;' : ($st['state'] === 'skip' ? '&ndash;' : '&#9675;')) . '</span><span class="kop-pb-step-text">' . $label
                . ($st['state'] !== '' ? ' <span class="screen-reader-text">(' . esc_html($kinds[$st['state']]) . ')</span>' : '') . '</span>' . $btn . '</li>';
        }
        $out .= '<details class="kop-pb-allsteps"><summary>All steps (' . count($steps) . ')</summary><ul>' . $list . '</ul></details></div>';
    }

    $out .= '<div class="kop-pb-answer">';
    if ($item_open) {
        $changed = $item_open['item_hash'] !== '' && $item_open['item_hash'] !== ($it['hash'] ?? '');
        $label = $kinds[$state] ?? '';
        $notes = array(
            'retire' => 'Retired: it leaves the plan on the next pass.',
            'research' => 'Needs research, saved ' . kop_pb_when($item_open['saved_at']) . '. On the next pass Claude looks into it and brings it back to you with what it found and the sources.',
            'claude' => 'Sent to Claude, saved ' . kop_pb_when($item_open['saved_at']) . '. Claude does it on the next pass and says what it did.',
        );
        $note = $notes[$state] ?? $label . ', saved ' . kop_pb_when($item_open['saved_at']) . '. Waiting for the next pass.';
        $out .= '<div class="kop-pb-saved">' . ($item_open['body'] !== '' && $item_open['body'] !== null ? nl2br(esc_html($item_open['body'])) : '<em>' . esc_html($label) . '</em>')
            . '<span class="kop-pb-when">' . esc_html($note) . ($changed ? ' The item has been reworded since.' : '') . '</span></div>';
        $out .= '<div class="kop-pb-buttons">' . (!kop_pb_handed_over($state) ? kop_ak_button('Change', 'secondary', 'data-act="edit-answer"') : '')
            . kop_ak_button('Undo', 'quiet', 'data-act="withdraw"') . '</div>';
        $out .= '<div class="kop-pb-form" data-form="answer" hidden>' . kop_pb_form_html($it, (string) $item_open['body'], 'Your answer') . '</div>';
    } elseif ($auto) {
        $out .= '<div class="kop-pb-saved">' . esc_html($auto['evidence'])
            . '<span class="kop-pb-when">' . esc_html('The site checked this off ' . wp_date('M j, g:i a', (int) $auto['at']) . '. Claude archives it on the next pass.') . '</span></div>';
        $out .= '<div class="kop-pb-buttons">' . kop_ak_button('Not done', 'secondary', 'data-act="notdone"') . '</div>';
    } else {
        if ($item_folded) {
            $out .= '<p class="kop-pb-folded">Last answer (' . esc_html($kinds[$item_folded['kind']] ?? '') . ', ' . esc_html(kop_pb_when($item_folded['saved_at'])) . ')'
                . ($item_folded['body'] !== '' && $item_folded['body'] !== null ? ': ' . esc_html(wp_trim_words((string) $item_folded['body'], 30)) : '')
                . '. Folded in ' . esc_html($item_folded['pass']['at']) . ($item_folded['pass']['result'] !== '' ? ': ' . kop_pb_md($item_folded['pass']['result']) : '') . '</p>';
        }
        $out .= '<div class="kop-pb-buttons kop-pb-quick">' . kop_pb_actions_html($it, (bool) $steps) . '</div>';
        $placeholder = $kind === 'check' ? 'What is wrong?' : ($kind === 'decide' ? 'Your answer' : 'A note: what happened, how far you got, what is in the way');
        $out .= '<div class="kop-pb-form" data-form="answer" hidden>' . kop_pb_form_html($it, '', $placeholder) . '</div>';
        $out .= '<div class="kop-pb-buttons kop-pb-handoff">' . kop_ak_button('Needs research', 'secondary', 'data-act="open-research"')
            . kop_ak_button('Send to Claude', 'secondary', 'data-act="open-claude"') . '</div>';
        $out .= '<div class="kop-pb-form" data-form="research" hidden>' . kop_pb_handoff_form_html($it, 'research',
            'Needs research: Claude looks into it on the next pass (KOP\'s own holdings first, then the web) and brings it back to you with what it found and the sources. What should it find out? (optional)',
            'Who runs it now, did the bill pass, is the address right...', 'Send for research') . '</div>';
        $out .= '<div class="kop-pb-form" data-form="claude" hidden>' . kop_pb_handoff_form_html($it, 'claude',
            'Send to Claude: Claude does this item itself on the next pass and says what it did. Anything it should know? (optional)',
            'How you want it done, what to leave alone...', 'Send to Claude') . '</div>';
    }

    // Edit and Retire, on every card.
    $edit = $it['edited'] ?? null;
    $out .= '<div class="kop-pb-buttons kop-pb-manage">' . kop_ak_button('Edit', 'quiet', 'data-act="open-edit"')
        . ($edit ? kop_ak_button('Undo my edit', 'quiet', 'data-act="unedit"') : '')
        . ($state !== 'retire' ? kop_ak_button('Retire', 'quiet', 'data-act="open-retire"') : '') . '</div>';
    $out .= '<div class="kop-pb-form" data-form="edit" hidden>' . kop_pb_edit_form_html($it) . '</div>';
    $out .= '<div class="kop-pb-form" data-form="retire" hidden>'
        . '<label for="' . esc_attr('kop-pb-rt-' . md5($it['id'])) . '">Retire this item: it comes off the board now and off the plan on the next pass. Why? (optional)</label>'
        . '<input type="text" id="' . esc_attr('kop-pb-rt-' . md5($it['id'])) . '" class="kop-pb-retire-why" placeholder="No longer matters, done another way, duplicate...">'
        . '<div class="kop-pb-buttons">' . kop_ak_button('Retire it', 'danger', 'data-act="retire"') . kop_ak_button('Cancel', 'quiet', 'data-act="cancel"') . '</div></div>';

    $out .= '<details class="kop-pb-details"><summary>Notes for Claude</summary><div class="kop-pb-text">' . kop_pb_md($it['text']) . '</div><p class="kop-pb-id">' . esc_html($it['id']) . '</p></details>';
    $out .= '<div class="kop-pb-msg" role="status" aria-live="polite"></div></article>';
    return $out;
}

/** The buttons for a card that is not answered yet, by its kind. */
function kop_pb_actions_html(array $it, $has_steps) {
    $out = '';
    $go_variant = 'primary';
    if (!$has_steps) {
        foreach ((array) $it['go'] as $g) {
            $out .= kop_pb_link_button($g['label'], $g['url'], $go_variant);
            $go_variant = 'secondary';
        }
    }
    if ($it['kind'] === 'decide') {
        foreach ((array) $it['choices'] as $c) {
            $out .= kop_ak_button($c, 'secondary', 'data-act="choice" data-choice="' . esc_attr($c) . '"', 'kop-pb-choice');
        }
        $out .= kop_ak_button($it['choices'] ? 'Other...' : 'Answer', $it['choices'] ? 'quiet' : 'primary', 'data-act="open"');
        $out .= kop_ak_button('Not now', 'quiet', 'data-act="later"');
        return $out;
    }
    if ($it['kind'] === 'check') {
        $out .= kop_ak_button('Looks right', 'secondary', 'data-act="done"');
        $out .= kop_ak_button('Something is wrong', 'secondary', 'data-act="open"');
        $out .= kop_ak_button('Not now', 'quiet', 'data-act="later"');
        return $out;
    }
    $out .= kop_ak_button($has_steps ? 'All done' : 'Done', 'secondary', 'data-act="done"');
    $out .= kop_ak_button('Add a note', 'quiet', 'data-act="open"');
    $out .= kop_ak_button('Not now', 'quiet', 'data-act="later"');
    return $out;
}

function kop_pb_form_html(array $it, $value, $placeholder) {
    $id = 'kop-pb-ta-' . md5($it['id']);
    return '<label class="screen-reader-text" for="' . esc_attr($id) . '">Your answer</label>'
        . '<textarea id="' . esc_attr($id) . '" class="kop-pb-ta" rows="3" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea($value) . '</textarea>'
        . '<div class="kop-pb-buttons">' . kop_ak_button('Save', 'primary', 'data-act="answer"')
        . kop_ak_button('Cancel', 'quiet', 'data-act="cancel"') . '<span class="kop-pb-hint">Ctrl+Enter saves</span></div>';
}

/** The Needs research / Send to Claude box: one optional line and its button. */
function kop_pb_handoff_form_html(array $it, $kind, $label, $placeholder, $button) {
    $id = 'kop-pb-' . $kind . '-' . md5($it['id']);
    return '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
        . '<textarea id="' . esc_attr($id) . '" class="kop-pb-handoff-text" rows="2" placeholder="' . esc_attr($placeholder) . '"></textarea>'
        . '<div class="kop-pb-buttons">' . kop_ak_button($button, 'primary', 'data-act="' . esc_attr($kind) . '"')
        . kop_ak_button('Cancel', 'quiet', 'data-act="cancel"') . '<span class="kop-pb-hint">Ctrl+Enter sends</span></div>';
}

/** Edit: the headline, the why, and the item's full text, as the owner wants them to read. */
function kop_pb_edit_form_html(array $it) {
    $h = md5($it['id']);
    return '<p class="kop-pb-hint">Reword this item. Your version shows here at once; Claude rewrites the plan file to match on the next pass.</p>'
        . '<label for="kop-pb-ea-' . $h . '">Headline (what to do or decide, one line)</label>'
        . '<input type="text" id="kop-pb-ea-' . $h . '" class="kop-pb-edit-ask" value="' . esc_attr($it['ask']) . '">'
        . '<label for="kop-pb-ew-' . $h . '">Why it matters (one line)</label>'
        . '<input type="text" id="kop-pb-ew-' . $h . '" class="kop-pb-edit-why" value="' . esc_attr($it['why']) . '">'
        . '<label for="kop-pb-et-' . $h . '">The full item</label>'
        . '<textarea id="kop-pb-et-' . $h . '" class="kop-pb-edit-text" rows="6">' . esc_textarea($it['text']) . '</textarea>'
        . '<div class="kop-pb-buttons">' . kop_ak_button('Save edit', 'primary', 'data-act="edit"') . kop_ak_button('Cancel', 'quiet', 'data-act="cancel"') . '</div>';
}

/**
 * Saves an answer for a target (an item, "<item>#<step>" or "<item>@edit"): withdraws the target's standing open
 * answer and adds this one. Returns the new row id or an error string.
 */
function kop_pb_save($target, $kind, $text, $user_id) {
    global $wpdb;
    $items = kop_pb_items(kop_pb_plan());
    list($item_id, $step) = kop_pb_target($target);
    if (!isset($items[$item_id])) return 'That item is no longer on the plan. Reload the page.';
    if ($step !== '' && !in_array($step, array_column((array) $items[$item_id]['steps'], 'id'), true)) return 'That step is no longer on the plan. Reload the page.';
    if (!in_array($kind, kop_pb_kinds_for($target), true)) return 'Unknown kind of answer.';
    $text = trim((string) $text);
    if ($kind === 'answer' && $text === '') return 'Type an answer first, or use Done or Not now.';
    if ($kind === 'edit') {
        $e = json_decode($text, true);
        if (!is_array($e) || trim(implode('', array_map('strval', $e))) === '') return 'Nothing to save in the edit.';
        $text = wp_json_encode(array('ask' => trim((string) ($e['ask'] ?? '')), 'why' => trim((string) ($e['why'] ?? '')), 'text' => trim((string) ($e['text'] ?? ''))));
    }
    kop_pb_withdraw($target);
    $ok = $wpdb->insert(kop_pb_table(), array(
        'item_id' => $target, 'item_hash' => (string) ($items[$item_id]['hash'] ?? ''), 'item_lead' => mb_substr((string) $items[$item_id]['lead'], 0, 255),
        'kind' => $kind, 'body' => $text, 'user_id' => (int) $user_id, 'saved_at' => current_time('mysql', true),
    ));
    return $ok ? (int) $wpdb->insert_id : 'Could not save: ' . $wpdb->last_error;
}

/** Withdraws the target's open answers (folded-in ones stay as they are). */
function kop_pb_withdraw($target) {
    global $wpdb;
    list($handled) = kop_pb_passes();
    $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . kop_pb_table() . ' WHERE item_id = %s AND withdrawn_at IS NULL', $target));
    $open = array_values(array_filter(array_map('intval', (array) $ids), function ($id) use ($handled) { return !isset($handled[$id]); }));
    if ($open) {
        $wpdb->query($wpdb->prepare('UPDATE ' . kop_pb_table() . ' SET withdrawn_at = %s WHERE id IN (' . implode(',', $open) . ')', current_time('mysql', true)));
    }
    return count($open);
}

/** The stored check results with the owner's "Not done" applied: item id => {done, evidence, at, overridden}. */
function kop_pb_check_results(array $items) {
    $checks = get_option('kop_pb_checks', array());
    $auto = kop_pb_auto_done($items, $checks);
    $out = array();
    foreach ((array) $checks as $id => $c) {
        if (!isset($items[$id]) || !is_array($c)) continue;
        $out[$id] = $c + array('overridden' => !empty($c['done']) && !isset($auto[$id]));
    }
    return $out;
}

/** The card as it stands now, for the AJAX reply. */
function kop_pb_card_now($item_id) {
    $items = kop_pb_items(kop_pb_plan());
    if (!isset($items[$item_id])) return '';
    list($handled) = kop_pb_passes();
    list($open, $folded) = kop_pb_split(kop_pb_rows(), $handled);
    $checks = kop_pb_check_results($items);
    return kop_pb_card_html($items[$item_id], $open, $folded, $checks[$item_id] ?? null);
}

add_action('wp_ajax_kop_plan_board', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Not allowed.'), 403);
    check_ajax_referer('kop_plan_board', 'nonce');
    kop_pb_install();
    $act = sanitize_key($_POST['act'] ?? '');
    $item = sanitize_text_field(wp_unslash($_POST['item'] ?? ''));
    $step = sanitize_text_field(wp_unslash($_POST['step'] ?? ''));
    $reply = function ($message) use ($item) {
        wp_send_json_success(array('message' => $message, 'html' => kop_pb_card_now($item), 'open' => kop_pb_open_count()));
    };
    if ($act === 'export') {
        list($handled) = kop_pb_passes();
        list($open) = kop_pb_split(kop_pb_rows(), $handled);
        $items = kop_pb_items(kop_pb_plan());
        wp_send_json_success(array('answers' => kop_pb_open_export($open, $items, kop_pb_auto_done($items))));
    }
    if ($act === 'notdone') {
        $items = kop_pb_items(kop_pb_plan());
        if (!isset($items[$item])) wp_send_json_error(array('message' => 'That item is no longer on the plan. Reload the page.'));
        kop_pb_check_override($item, $items[$item]['hash'] ?? '');
        $reply('Back on your list.');
    }
    if ($act === 'withdraw') { kop_pb_withdraw($item); $reply('Undone.'); }
    if ($act === 'unedit') { kop_pb_withdraw($item . '@edit'); $reply('Your edit is undone.'); }
    if ($act === 'unstep') { kop_pb_withdraw($item . '#' . $step); $reply('Step undone.'); }
    $targets = array('answer' => $item, 'done' => $item, 'later' => $item, 'retire' => $item, 'research' => $item, 'claude' => $item, 'edit' => $item . '@edit',
        'step' => $item . '#' . $step, 'skip' => $item . '#' . $step);
    if (isset($targets[$act])) {
        $res = kop_pb_save($targets[$act], $act, wp_unslash($_POST['text'] ?? ''), get_current_user_id());
        if (!is_int($res)) wp_send_json_error(array('message' => $res));
        $msgs = array('research' => 'Marked Needs research. Claude looks into it on the next pass.', 'claude' => 'Sent to Claude. It does this on the next pass.', 'retire' => 'Retired.', 'edit' => 'Edit saved.', 'step' => 'Step done.', 'skip' => 'Step skipped.', 'later' => 'Parked for later.');
        $reply($msgs[$act] ?? 'Saved.');
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
    $checks = kop_pb_check_results($items);

    echo '<div class="wrap kop-ak kop-pb">';
    echo kop_ak_header('Plan Board', array(
        'lede' => 'Everything waiting on you, one task per card. <em>This week</em> is the short list; <em>Everything for you</em> is the rest.',
        'steps' => array(
            '<strong>Decide</strong> cards: press one of the choices (or <em>Other...</em> to type). <strong>Do</strong> cards: <em>Open</em> takes you there; press <em>Done</em> after. <strong>Check</strong> cards: look, then <em>Looks right</em> or <em>Something is wrong</em>.',
            'A job with steps shows only the next step: <em>Done, next</em> or <em>Skip</em>. <em>All steps</em> lists the rest.',
            '<em>Edit</em> rewords a card, <em>Retire</em> takes it off the board. Both show at once; <em>Not now</em> parks it.',
            '<em>Needs research</em>: Claude looks into it and brings it back with what it found. <em>Send to Claude</em>: Claude does it for you. Both move the card to <em>Waiting for Claude</em>.',
            'Say "next pass" to Claude: it makes the plan match your answers and does the work they unblock. Until then, <em>Undo</em> takes any of it back.',
        ),
        'help' => '<p>The cards come from the plan files in the theme repository (docs/plan/), rebuilt on every push that changes them. <em>Notes for Claude</em> on each card is the full item as Claude wrote it. Claude never writes your answers or this screen: it reads them and commits what it did.</p>',
    ));

    $status = '<strong class="kop-pb-open-count">' . count($open) . '</strong> answers waiting for Claude'
        . ($plan['built'] !== '' ? ' &middot; plan built ' . esc_html($plan['built']) : '');
    echo kop_ak_status($status, kop_ak_button('Copy open answers', 'secondary', '', 'kop-pb-copy'));

    if ($passes) {
        $p = $passes[0];
        $list = '';
        foreach ((array) ($p['entries'] ?? array()) as $e) {
            $list .= '<li><strong>' . esc_html($e['lead'] ?? $e['itemId'] ?? '') . '</strong>'
                . (!empty($e['kind']) ? ' <span class="kop-pb-chip is-' . esc_attr($e['kind']) . '">' . esc_html(kop_pb_kinds()[$e['kind']] ?? $e['kind']) . '</span>' : '')
                . '<br><span class="kop-pb-result">' . kop_pb_md($e['result'] ?? '') . '</span></li>';
        }
        echo kop_ak_fold('What Claude did last pass (' . esc_html($p['at'] ?? '') . ')', '<ul class="kop-pb-pass">' . $list . '</ul>', false, 'kop-pb-lastpass');
    }

    $lost = array_filter($open, function ($r, $row_id) use ($items) { return !isset($items[kop_pb_target($row_id)[0]]); }, ARRAY_FILTER_USE_BOTH);
    if ($lost) {
        $list = '';
        foreach ($lost as $row_id => $r) {
            $list .= '<li><span class="kop-pb-id">' . esc_html($row_id) . '</span> (' . esc_html($r['item_lead']) . '): ' . esc_html($r['body'] !== '' ? $r['body'] : (kop_pb_kinds()[$r['kind']] ?? '')) . '</li>';
        }
        echo kop_ak_fold('Answers to items no longer on the plan (' . count($lost) . ')', '<ul class="kop-pb-pass">' . $list . '</ul><p>Claude still reads these on its next pass.</p>', false);
    }

    // The toolbar: the view, the kind, a section, the search box. Applied in the page (js/plan-board.js), which keeps the counts.
    $views = array('week' => 'This week', 'needs' => 'Everything for you', 'waiting' => 'Waiting for Claude', 'all' => 'Whole plan');
    echo '<div class="kop-pb-toolbar"><div class="kop-pb-tabs" role="toolbar" aria-label="View">';
    foreach ($views as $key => $label) {
        echo '<button type="button" class="kop-pb-tab" data-view="' . esc_attr($key) . '" aria-pressed="' . ($key === 'week' ? 'true' : 'false') . '">'
            . esc_html($label) . ' <span class="kop-pb-n" data-count="' . esc_attr($key) . '">0</span></button>';
    }
    echo '</div><div class="kop-pb-row"><label class="screen-reader-text" for="kop-pb-kind">Kind</label><select id="kop-pb-kind" class="kop-pb-kind-select">'
        . '<option value="">Every kind</option>';
    foreach (kop_pb_card_kinds() as $k => $label) echo '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
    echo '</select><label class="screen-reader-text" for="kop-pb-group">Section</label><select id="kop-pb-group" class="kop-pb-group-select"><option value="">All sections</option>';
    foreach ($plan['sections'] as $s) {
        foreach ($s['groups'] as $g) {
            if (!$g['items']) continue;
            echo '<option value="' . esc_attr($g['key']) . '">' . esc_html(kop_pb_group_label($s, $g)) . '</option>';
        }
    }
    echo '</select><input type="search" class="kop-pb-search" placeholder="Find a card" aria-label="Find a card"></div></div>';

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
                echo kop_pb_card_html($items[$it['id']], $open, $folded, $checks[$it['id']] ?? null);
            }
            echo '</div>';
        }
        echo '</section>';
    }
    // This week and Everything for you show their cards here as one list, quickest kinds first
    // (js/plan-board.js moves them in and back).
    echo '<div class="kop-pb-flat" hidden></div>';
    echo '<p class="kop-pb-none" hidden>Nothing matches.</p>';
    echo '</div>';
}
