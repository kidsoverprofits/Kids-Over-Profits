<?php
/**
 * Practice round: a new volunteer's first 10 calls are on items an admin has
 * already decided, instead of waiting for an admin to check 10 real ones.
 *
 * The volunteer picks a queue open to everyone (kop_vol_sources()); the round
 * is 10 items drawn from the admins' own approve / reject rows in
 * {prefix}kop_review_log for it (not undone, not 'auto' or the volunteers'
 * own calls going through, one clear answer per item), about half of them
 * rejects so that always saying Approve does not pass. Each item reaches them
 * as a kop_vol_item() copy with whatever says how it was decided taken out
 * (kop_volpr_copy()). After each call the page says what the admin decided.
 * 8 of 10 right passes, and passing opens "Pick your next batch"
 * (kop_volp_self_status()) for that queue until the volunteer has a real
 * record of 10 admin-checked calls, which then decides as before.
 *
 * Practice calls live only in {prefix}kop_vol_practice: they never touch
 * {prefix}kop_volunteer_recs, so they are never recommendations, never close
 * anything, and never count toward agreement rates or inc/volunteer-auto.php.
 *
 * REST (volunteer cookie + X-KOP-Vol): kop/v1/volunteer/practice with
 * do = start | next | answer. Tested by scripts/test-volunteer-projects.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_VOLPR_DB_VERSION = '1';

function kop_volpr_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_vol_practice';
}

function kop_volpr_ensure_tables() {
    if (get_option('kop_vol_practice_db') === KOP_VOLPR_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = kop_volpr_table();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        volunteer_id BIGINT UNSIGNED NOT NULL,
        round_no INT UNSIGNED NOT NULL DEFAULT 1,
        source VARCHAR(40) NOT NULL,
        item_key VARCHAR(191) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        answer VARCHAR(12) NOT NULL,
        verdict VARCHAR(12) NOT NULL DEFAULT '',
        position INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        answered_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY one_each (volunteer_id, source, item_key),
        KEY round (volunteer_id, round_no)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('kop_vol_practice_db', KOP_VOLPR_DB_VERSION);
}

/** A round's size, how many right pass it, and the fewest approves and rejects a round holds. */
function kop_volpr_rules() {
    $size = max(2, (int) apply_filters('kop_volpr_size', 10));
    return array(
        'size' => $size,
        'pass' => min($size, max(1, (int) apply_filters('kop_volpr_pass', (int) ceil(0.8 * $size)))),
        'each' => max(1, min((int) floor($size / 2), (int) apply_filters('kop_volpr_min_each', 3))),
    );
}

/** Reviewer names in the log that are not a person deciding: rules filing things, volunteers' calls going through. */
function kop_volpr_not_people() {
    return array('', 'auto', KOP_VOL_AUTO_BY);
}

/**
 * Items an admin decided one way ($style 'approve' or 'reject') in this queue, newest first: [item_key => title].
 * An item decided both ways (one undone by hand, or changed later) is left out.
 */
function kop_volpr_decided($source, $style, $limit = 300) {
    global $wpdb;
    if (function_exists('kop_rinbox_ensure_tables')) kop_rinbox_ensure_tables();
    $not = kop_volpr_not_people();
    $rows = $wpdb->get_results($wpdb->prepare('SELECT item_key, MAX(title) AS title FROM ' . kop_rinbox_log_table() . "
        WHERE source = %s AND style IN ('approve', 'reject') AND undone_at IS NULL AND user_login NOT IN (" . implode(', ', array_fill(0, count($not), '%s')) . ')
        GROUP BY item_key HAVING COUNT(DISTINCT style) = 1 AND MAX(style) = %s ORDER BY MAX(id) DESC LIMIT %d',
        array_merge(array((string) $source), $not, array((string) $style, (int) $limit))), ARRAY_A);
    $out = array();
    foreach ((array) $rows as $r) $out[(string) $r['item_key']] = (string) $r['title'];
    return $out;
}

/** Details and links that say how an item was decided ("Reviewed by", "Filed PDF", the record it was linked to). */
function kop_volpr_hidden_fact($label) {
    return (bool) preg_match('/reviewed|decided|handled|\bfiled\b|listed on|facility now|^facility$|^status$|applied|approved|rejected|dismissed/i', (string) $label);
}

/** A decided item as a practice card: the volunteer copy without what gives the answer away. */
function kop_volpr_copy($source, array $it) {
    $v = kop_vol_item($source, $it);
    $v['facts'] = array_values(array_filter($v['facts'], function ($f) { return !kop_volpr_hidden_fact($f['label']); }));
    $v['links'] = array_values(array_filter($v['links'], function ($l) { return !kop_volpr_hidden_fact($l['label']); }));
    // The linked record (its place and kind in the subtitle) and what the buttons did differ between the approved and the rejected ones.
    $v['subtitle'] = '';
    $v['facility'] = null;
    $v['approve'] = array();
    $v['reject'] = array();
    $v['practice'] = true;
    return $v;
}

/** The item as it is now, if it can still be shown and is not waiting on a real decision again. */
function kop_volpr_fetch($source, $key) {
    $all = function_exists('kop_rinbox_sources') ? kop_rinbox_sources() : array();
    if (!isset($all[$source])) return null;
    $it = kop_vol_raw_item($all[$source], $source, $key);
    if (!$it || kop_vol_actionable($source, $it)) return null;
    return $it;
}

/** Queues open to everyone with enough decided items for a round: [key => label]. Counts only, no items read. */
function kop_volpr_queues() {
    $rules = kop_volpr_rules();
    $out = array();
    foreach (kop_vol_sources() as $key => $src) {
        if (empty($src['get'])) continue;
        $yes = count(kop_volpr_decided($key, 'approve', $rules['size']));
        $no = count(kop_volpr_decided($key, 'reject', $rules['size']));
        if ($yes >= $rules['each'] && $no >= $rules['each'] && $yes + $no >= $rules['size']) $out[$key] = (string) $src['label'];
    }
    return $out;
}

/**
 * Draw a round: up to half rejects, at least 'each' of both, items this volunteer has not practised or recommended.
 * Returns [[key, title, answer], ...] in the order shown, or throws when the queue has too few left.
 */
function kop_volpr_draw($vid, $source) {
    global $wpdb;
    $rules = kop_volpr_rules();
    $seen = array();
    foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_volpr_table() . ' WHERE volunteer_id = %d AND source = %s', (int) $vid, $source)) as $k) $seen[(string) $k] = true;
    foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_vol_recs_table() . ' WHERE volunteer_id = %d AND source = %s', (int) $vid, $source)) as $k) $seen[(string) $k] = true;
    $want = array('reject' => (int) floor($rules['size'] / 2));
    $want['approve'] = $rules['size'] - $want['reject'];
    $got = array('approve' => array(), 'reject' => array());
    foreach (array('reject', 'approve') as $style) {
        // Fewer rejects than half: the approves make up the rest.
        if ($style === 'approve') $want['approve'] = $rules['size'] - count($got['reject']);
        $pool = array_diff_key(kop_volpr_decided($source, $style), $seen);
        $keys = array_keys($pool);
        shuffle($keys);
        // Reading items is the slow part: stop after a few times what is needed.
        foreach (array_slice($keys, 0, 4 * $want[$style] + 4) as $k) {
            if (count($got[$style]) >= $want[$style]) break;
            $it = kop_volpr_fetch($source, (string) $k);
            if (!$it) continue;
            $got[$style][] = array((string) $k, (string) (($it['title'] ?? '') ?: $pool[$k]), $style);
        }
    }
    if (count($got['approve']) < $rules['each'] || count($got['reject']) < $rules['each'] || count($got['approve']) + count($got['reject']) < $rules['size']) {
        throw new RuntimeException('This queue does not have enough decided items for a practice round. Pick another one.');
    }
    $round = array_merge($got['approve'], $got['reject']);
    shuffle($round);
    return $round;
}

/** Every round of this volunteer's, oldest first: [{round, source, label, total, done, right, finished, passed}]. Gone items do not count. */
function kop_volpr_rounds($vid) {
    global $wpdb;
    kop_volpr_ensure_tables();
    $rows = $wpdb->get_results($wpdb->prepare('SELECT round_no, source, answer, verdict FROM ' . kop_volpr_table() . ' WHERE volunteer_id = %d ORDER BY round_no, position', (int) $vid), ARRAY_A);
    $rules = kop_volpr_rules();
    $labels = function_exists('kop_rinbox_sources') ? kop_rinbox_sources() : array();
    $out = array();
    foreach ((array) $rows as $r) {
        $n = (int) $r['round_no'];
        if (!isset($out[$n])) $out[$n] = array('round' => $n, 'source' => $r['source'], 'label' => (string) ($labels[$r['source']]['label'] ?? $r['source']),
            'total' => 0, 'done' => 0, 'right' => 0, 'waiting' => 0);
        if ($r['verdict'] === 'gone') continue;
        $out[$n]['total']++;
        if ($r['verdict'] === '') { $out[$n]['waiting']++; continue; }
        $out[$n]['done']++;
        if ($r['verdict'] === $r['answer']) $out[$n]['right']++;
    }
    foreach ($out as $n => $x) {
        $out[$n]['finished'] = !$x['waiting'];
        // A card that left its queue mid-round shrinks the round; the share needed stays the same.
        $out[$n]['passed'] = !$x['waiting'] && $x['total'] > 0 && $x['right'] * $rules['size'] >= $rules['pass'] * $x['total'];
        unset($out[$n]['waiting']);
    }
    return array_values($out);
}

/** The queues this volunteer has passed a practice round in. */
function kop_volpr_passed($vid) {
    $out = array();
    foreach (kop_volpr_rounds($vid) as $r) if ($r['passed']) $out[$r['source']] = true;
    return array_keys($out);
}

/**
 * What the volunteer page shows: {size, pass, open (the unfinished round or null), last (the last finished one or null),
 * passed [sources], queues [{key, label}] to start a round in (open to everyone, enough decided items, not passed yet)}.
 */
function kop_volpr_status($vid) {
    $rules = kop_volpr_rules();
    $out = array('size' => $rules['size'], 'pass' => $rules['pass'], 'open' => null, 'last' => null, 'passed' => array(), 'queues' => array());
    if (!(int) $vid) return $out;
    foreach (kop_volpr_rounds($vid) as $r) {
        if (!$r['finished']) $out['open'] = $r;
        else $out['last'] = $r;
        if ($r['passed']) $out['passed'][] = $r['source'];
    }
    $out['passed'] = array_values(array_unique($out['passed']));
    foreach (kop_volpr_queues() as $key => $label) {
        if (!in_array($key, $out['passed'], true)) $out['queues'][] = array('key' => $key, 'label' => $label);
    }
    return $out;
}

/** Start a round in this queue (or carry on the one open). Returns kop_volpr_status(). */
function kop_volpr_start(array $vol, $source) {
    global $wpdb;
    $vid = (int) $vol['id'];
    $st = kop_volpr_status($vid);
    if ($st['open']) return $st;
    $source = sanitize_key((string) $source);
    if (!isset(kop_vol_sources()[$source])) throw new RuntimeException('Pick one of the queues listed.');
    if (in_array($source, $st['passed'], true)) throw new RuntimeException('You passed the practice round for this queue already.');
    kop_vol_rate_check($vid);
    $round = kop_volpr_draw($vid, $source);
    $no = 1 + (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(round_no) FROM ' . kop_volpr_table() . ' WHERE volunteer_id = %d', $vid));
    $now = current_time('mysql', true);
    foreach ($round as $i => $r) {
        $wpdb->insert(kop_volpr_table(), array('volunteer_id' => $vid, 'round_no' => $no, 'source' => $source, 'item_key' => $r[0],
            'title' => mb_substr($r[1], 0, 255), 'answer' => $r[2], 'position' => $i, 'created_at' => $now));
    }
    return kop_volpr_status($vid);
}

/** The open round's next card: {round, brief, item (or null when every card has an answer)}. */
function kop_volpr_next($vid) {
    global $wpdb;
    $st = kop_volpr_status($vid);
    if (!$st['open']) throw new RuntimeException('Start a practice round first.');
    $r = $st['open'];
    $k = kop_volp_kind($r['source']);
    $brief = array('title' => 'Practice: ' . $k['title'], 'question' => $k['question'], 'yes' => $k['yes'], 'no' => $k['no']);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT id, item_key FROM ' . kop_volpr_table() . " WHERE volunteer_id = %d AND round_no = %d AND verdict = '' ORDER BY position",
        (int) $vid, (int) $r['round']), ARRAY_A);
    foreach ((array) $rows as $row) {
        $it = kop_volpr_fetch($r['source'], (string) $row['item_key']);
        if ($it) return array('round' => $r, 'brief' => $brief, 'item' => kop_volpr_copy($r['source'], $it));
        // Left its queue, or waiting on a real decision again: out of the round.
        $wpdb->update(kop_volpr_table(), array('verdict' => 'gone', 'answered_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
    }
    $rounds = kop_volpr_rounds($vid);
    return array('round' => end($rounds), 'brief' => $brief, 'item' => null);
}

/** A practice call. Returns {right, answer, said, round (with finished and passed)}. Nothing outside the practice table changes. */
function kop_volpr_answer(array $vol, $key, $verdict) {
    global $wpdb;
    $vid = (int) $vol['id'];
    if (!in_array($verdict, array('approve', 'reject', 'unsure'), true)) throw new InvalidArgumentException('Pick approve, reject or not sure.');
    $st = kop_volpr_status($vid);
    if (!$st['open']) throw new RuntimeException('Start a practice round first.');
    $row = $wpdb->get_row($wpdb->prepare('SELECT id, answer FROM ' . kop_volpr_table() . " WHERE volunteer_id = %d AND round_no = %d AND item_key = %s AND verdict = ''",
        $vid, (int) $st['open']['round'], (string) $key), ARRAY_A);
    if (!$row) throw new RuntimeException('That card is not in your practice round. Reload the page.');
    kop_vol_rate_check($vid);
    $wpdb->update(kop_volpr_table(), array('verdict' => $verdict, 'answered_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
    $round = null;
    foreach (kop_volpr_rounds($vid) as $r) if ($r['round'] === (int) $st['open']['round']) $round = $r;
    return array('right' => $verdict === $row['answer'], 'answer' => $row['answer'],
        'said' => $row['answer'] === 'approve' ? 'An admin approved this one.' : 'An admin rejected this one.', 'round' => $round);
}

/** One line for the Volunteers table: "Practice: News 9 of 10, passed". Never mixed into the agreement rate. */
function kop_volpr_summary($vid) {
    $parts = array();
    foreach (kop_volpr_rounds($vid) as $r) {
        $parts[] = $r['label'] . ' ' . $r['right'] . ' of ' . $r['total'] . ($r['finished'] ? ($r['passed'] ? ', passed' : ', not passed') : ', not finished');
    }
    return $parts ? 'Practice: ' . implode('; ', $parts) : '';
}

/* ---- REST ------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/volunteer/practice', array(
        'methods' => 'POST', 'callback' => 'kop_volpr_rest',
        'permission_callback' => function (WP_REST_Request $req) {
            $who = kop_vol_rest_who($req);
            return is_wp_error($who) ? $who : true;
        },
    ));
});

function kop_volpr_rest(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        if (empty($who['vol'])) throw new RuntimeException('Admins see this page as a preview; practice rounds are for volunteers.');
        $vol = $who['vol'];
        $do = sanitize_key((string) $req->get_param('do'));
        if ($do === 'start') return kop_volpr_start($vol, (string) $req->get_param('source'));
        if ($do === 'next') return kop_volpr_next((int) $vol['id']);
        if ($do === 'answer') return kop_volpr_answer($vol, (string) $req->get_param('key'), (string) $req->get_param('verdict'));
        return kop_volpr_status((int) $vol['id']);
    });
}
