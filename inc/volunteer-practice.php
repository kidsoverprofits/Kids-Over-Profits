<?php
/**
 * Practice round: a new volunteer's first 10 calls are on items an admin has
 * already decided, instead of waiting for an admin to check 10 real ones.
 *
 * A new volunteer starts with the starter queue (kop_vol_starter_source(), the
 * violation findings); passing a round opens that queue to them
 * (kop_vol_sources_for()), and each other queue opens after its own round. The round
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

/**
 * Decided items too tricky to practise on: [item_key => why]. A volunteer said Not sure or called it the other way, two
 * or more people got it wrong in practice, a Brief FAQ entry rests on it (its "#123" or "#123-130" in 'from'), or an admin
 * struck it on the Practice cards list (option kop_volpr_struck: [source => [key => true]]).
 */
function kop_volpr_tricky($source) {
    global $wpdb;
    $source = (string) $source;
    $out = array();
    kop_vol_ensure_tables();
    foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_vol_recs_table() . " WHERE source = %s AND (verdict = 'unsure'
        OR (outcome IN ('approve', 'reject') AND verdict IN ('approve', 'reject') AND verdict <> outcome))", $source)) as $k) $out[(string) $k] = 'a volunteer disagreed or was not sure';
    kop_volpr_ensure_tables();
    foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_volpr_table() . " WHERE source = %s AND verdict IN ('approve', 'reject', 'unsure')
        AND verdict <> answer GROUP BY item_key HAVING COUNT(*) >= 2", $source)) as $k) $out[(string) $k] = 'two or more got it wrong in practice';
    if (function_exists('kop_volf_list')) {
        foreach (kop_volf_list($source) as $e) {
            preg_match_all('/#(\d+)(?:\s*-\s*(\d+))?/', (string) ($e['from'] ?? ''), $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $to = isset($hit[2]) && $hit[2] !== '' ? min((int) $hit[2], (int) $hit[1] + 50) : (int) $hit[1];
                for ($n = (int) $hit[1]; $n <= $to; $n++) $out[(string) $n] = 'a Brief FAQ answer rests on it';
            }
        }
    }
    foreach (array_keys((array) (get_option('kop_volpr_struck', array())[$source] ?? array())) as $k) $out[(string) $k] = 'struck by an admin';
    return $out;
}

/** What a card gives to read: its text, or else a summary-like line (news keeps its summary in the facts). '' = nothing. */
function kop_volpr_readable(array $card) {
    $text = trim((string) ($card['text'] ?? ''));
    if ($text !== '') return $text;
    foreach ((array) ($card['facts'] ?? array()) as $f) {
        $v = trim((string) ($f['value'] ?? ''));
        if (mb_strlen($v) >= 40 && preg_match('/summary|quote|excerpt|finding|description|what it says/i', (string) ($f['label'] ?? ''))) return $v;
    }
    return '';
}

/** A practice card a newcomer cannot judge from the card: nothing to read, or a link that sits behind a CAPTCHA. */
function kop_volpr_unclear(array $card) {
    if (kop_volpr_readable($card) === '') return true;
    $host = strtolower((string) parse_url((string) ($card['url'] ?? ''), PHP_URL_HOST));
    return (bool) preg_match('/(^|\.)archive\.(md|ph|is|li|today|vn|fo)$/', $host);
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
    // The starter queue (violations) first: the page offers it as the one to begin with.
    foreach (kop_vol_earnable_sources() as $key => $src) {
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
    $seen += kop_volpr_tricky($source);
    $want = array('reject' => (int) floor($rules['size'] / 2));
    $want['approve'] = $rules['size'] - $want['reject'];
    $got = array('approve' => array(), 'reject' => array());
    // The same story sent in twice (one approved, its copy rejected as a duplicate) would show the same card twice.
    $titles = array();
    foreach (array('reject', 'approve') as $style) {
        // Fewer rejects than half: the approves make up the rest.
        if ($style === 'approve') $want['approve'] = $rules['size'] - count($got['reject']);
        $pool = array_diff_key(kop_volpr_decided($source, $style), $seen);
        $keys = array_keys($pool);
        shuffle($keys);
        // Reading items is the slow part: stop after a few times what is needed.
        foreach (array_slice($keys, 0, 8 * $want[$style] + 8) as $k) {
            if (count($got[$style]) >= $want[$style]) break;
            $it = kop_volpr_fetch($source, (string) $k);
            if (!$it || kop_volpr_unclear(kop_volpr_copy($source, $it))) continue;
            $title = (string) (($it['title'] ?? '') ?: $pool[$k]);
            $same = strtolower(trim(preg_replace('/\s+/', ' ', $title)));
            if ($same !== '' && isset($titles[$same])) continue;
            $titles[$same] = true;
            $got[$style][] = array((string) $k, $title, $style);
        }
    }
    if (count($got['approve']) < $rules['each'] || count($got['reject']) < $rules['each'] || count($got['approve']) + count($got['reject']) < $rules['size']) {
        throw new RuntimeException('This queue does not have enough decided items for a practice round. Pick another one.');
    }
    $round = array_merge($got['approve'], $got['reject']);
    shuffle($round);
    return $round;
}

/** What a queue's work is, in a line, for "Start now" on the volunteer page ('' = the title says enough). */
function kop_volpr_blurb($source) {
    $b = array(
        'inspection-highlights' => 'Each one is a passage our scanner pulled out of a state inspection report. You say whether it is a serious violation '
            . '(harm or danger to a young person) or paperwork. Most take about a minute, and this is the work we most need help with.',
    );
    return (string) apply_filters('kop_volpr_blurb', $b[$source] ?? '', $source);
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
        if (!in_array($key, $out['passed'], true)) $out['queues'][] = array('key' => $key, 'label' => $label,
            'title' => (string) (kop_volp_kinds()[$key]['title'] ?? $label), 'blurb' => kop_volpr_blurb($key));
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
    if (!isset(kop_vol_earnable_sources()[$source])) throw new RuntimeException('Pick one of the queues listed.');
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

/* ---- KOP Tools > Volunteer Reviewers > Practice cards ------------------------------------ */

function kop_volpr_admin_url($source) {
    return add_query_arg('practice', rawurlencode((string) $source), admin_url('admin.php?page=kop-volunteer-reviewers'));
}

/** Strike a card from practice, or put it back (called from kop_volp_admin_post()): [notice, error, null] or null when not ours. */
function kop_volpr_admin_post($do, array $in) {
    if (!in_array($do, array('practice_strike', 'practice_unstrike'), true)) return null;
    $source = sanitize_key((string) ($in['kop_volpr_source'] ?? ''));
    $key = sanitize_text_field((string) ($in['kop_volpr_key'] ?? ''));
    if ($source === '' || $key === '') return array('', 'Pick a card.', null);
    $struck = (array) get_option('kop_volpr_struck', array());
    if ($do === 'practice_strike') $struck[$source][$key] = true;
    else unset($struck[$source][$key]);
    update_option('kop_volpr_struck', $struck, false);
    return array($do === 'practice_strike' ? 'Taken out of practice rounds.' : 'Back in practice rounds.', '', null);
}

/** One line for the Projects section: a link to each queue's practice cards, the starter queue first. */
function kop_volpr_admin_summary() {
    $parts = array();
    foreach (kop_volpr_queues() as $key => $label) {
        $title = (string) (kop_volp_kinds()[$key]['title'] ?? $label);
        $parts[] = '<a href="' . esc_url(kop_volpr_admin_url($key)) . '">' . esc_html($title) . '</a>';
    }
    if (!$parts) return;
    echo '<p style="max-width:900px"><strong>Practice cards</strong> (chosen for you: the clear cases; strike any you think is tricky): ' . implode(' · ', $parts) . '</p>';
}

/** The cards a practice round in this queue can draw, newest first, each with Take out; the struck ones below with Put back. */
function kop_volpr_admin_page($source, $nonce) {
    $source = sanitize_key((string) $source);
    $base = admin_url('admin.php?page=kop-volunteer-reviewers');
    $title = (string) (kop_volp_kinds()[$source]['title'] ?? (kop_rinbox_sources()[$source]['label'] ?? $source));
    $tricky = kop_volpr_tricky($source);
    $struck = (array) (get_option('kop_volpr_struck', array())[$source] ?? array());
    ?>
    <p><a href="<?php echo esc_url($base . '#projects'); ?>">&larr; All projects</a></p>
    <h2>Practice cards: <?php echo esc_html($title); ?></h2>
    <p style="max-width:760px">A practice round draws from these: items you decided, minus any a volunteer disagreed on or was not sure about,
        any two people got wrong in practice, any a Brief FAQ answer rests on, and any with no text to read. Nothing here waits for you;
        take out any card you think is tricky and it never comes up again.</p>
    <?php
    foreach (array('approve' => 'You approved these', 'reject' => 'You rejected these') as $style => $heading) {
        echo '<h3>' . esc_html($heading) . '</h3>';
        $shown = 0;
        $left_out = 0;
        foreach (kop_volpr_decided($source, $style, 200) as $key => $t) {
            if ($shown >= 25) break;
            if (isset($tricky[(string) $key])) { $left_out++; continue; }
            $it = kop_volpr_fetch($source, (string) $key);
            if (!$it) continue;
            $card = kop_volpr_copy($source, $it);
            if (kop_volpr_unclear($card)) { $left_out++; continue; }
            $shown++;
            kop_volpr_admin_card($source, (string) $key, $card, $nonce, 'practice_strike', 'Take out of practice');
        }
        if (!$shown) echo '<p>None yet.</p>';
        if ($left_out) echo '<p class="description">' . (int) $left_out . ' more left out on their own (tricky or no text) among the newest.</p>';
    }
    if ($struck) {
        echo '<h3>Taken out by you</h3>';
        foreach (array_keys($struck) as $key) {
            $it = kop_volpr_fetch($source, (string) $key);
            $card = $it ? kop_volpr_copy($source, $it) : array('title' => '#' . $key . ' (no longer on file)', 'text' => '');
            kop_volpr_admin_card($source, (string) $key, $card, $nonce, 'practice_unstrike', 'Put back');
        }
    }
}

function kop_volpr_admin_card($source, $key, array $card, $nonce, $do, $button) {
    $text = kop_volpr_readable($card);
    ?>
    <form method="post" style="max-width:900px;margin:0 0 10px;padding:10px 14px;background:#fff;border:1px solid #c3c4c7">
        <?php echo $nonce; ?><input type="hidden" name="kop_volp_do" value="<?php echo esc_attr($do); ?>">
        <input type="hidden" name="kop_volpr_source" value="<?php echo esc_attr($source); ?>"><input type="hidden" name="kop_volpr_key" value="<?php echo esc_attr($key); ?>">
        <p style="margin:0 0 4px"><strong><?php echo esc_html((string) ($card['title'] ?? '')); ?></strong> <span class="description">#<?php echo esc_html($key); ?></span></p>
        <?php if ($text !== '') : ?><p style="margin:0 0 6px"><?php echo esc_html(mb_strimwidth($text, 0, 400, '...')); ?></p><?php endif; ?>
        <button type="submit" class="button button-small"><?php echo esc_html($button); ?></button>
    </form>
    <?php
}
