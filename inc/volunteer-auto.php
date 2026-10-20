<?php
/**
 * Volunteers' agreed calls go through on their own: the owner's switch at
 * KOP Tools > Volunteer Reviewers, off until turned on.
 *
 * When it is on, a volunteer's approve or reject (kop_vol_recommend()) is
 * applied at once when, on that item:
 * - its queue is ticked for this, and only kop_vol_auto_allowed() can be:
 *   queues where a wrong call is a small record change Undo puts back. Never
 *   publishing, a named person, survivor material, a public status, merges or
 *   the five submission queues;
 * - at least 'agree' trusted volunteers said the same and nobody said
 *   otherwise (a Not sure holds it for the admin);
 * - trusted = 'decided' calls the admin decided, agreeing 'rate' % or more;
 * - the card's own button runs with what the card holds (kop_volp_pick_action());
 * - it has never gone through this way before (once an admin Undoes one, the
 *   item is theirs).
 * The button runs through kop_rinbox_apply_action() as reviewer 'volunteers'
 * (KOP_VOL_AUTO_BY), so it is in Recently done with its Undo and in "Gone
 * through on their own" on the Volunteer Reviewers screen. Calls closed this
 * way never count toward an agreement rate (kop_vol_list(),
 * kop_volp_self_status()): trust comes from the admin's own decisions.
 * Turning it off stops new ones; nothing already applied changes.
 *
 * Option kop_vol_auto {on, sources, agree, decided, rate}.
 * Tested by scripts/test-review-volunteers.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Queues that may be ticked: a wrong call there is a small, undoable record change. */
function kop_vol_auto_allowed() {
    return array('doc-types', 'scraper-finds', 'websites', 'woodbury-reports', 'map-years', 'folder-links', 'map-renames',
        'inspection-links', 'wiki-links', 'program-homes', 'company-milestones', 'federal-funding', 'nonprofit-filings',
        'woodbury-facts', 'program-practices', 'indigenous-schools');
}

/** Ticked until the admin chooses. */
function kop_vol_auto_default_sources() {
    return array('doc-types', 'scraper-finds', 'websites', 'woodbury-reports', 'map-years', 'folder-links');
}

function kop_vol_auto_settings() {
    $o = get_option('kop_vol_auto', array());
    $o = is_array($o) ? $o : array();
    return array(
        'on'      => !empty($o['on']),
        'sources' => isset($o['sources']) && is_array($o['sources'])
            ? array_values(array_intersect($o['sources'], kop_vol_auto_allowed())) : kop_vol_auto_default_sources(),
        'agree'   => min(5, max(2, (int) ($o['agree'] ?? 2))),
        'decided' => min(500, max(5, (int) ($o['decided'] ?? 20))),
        'rate'    => min(100, max(50, (int) ($o['rate'] ?? 90))),
    );
}

function kop_vol_auto_save(array $in) {
    $cur = kop_vol_auto_settings();
    $sources = array_values(array_intersect(array_map('sanitize_key', (array) ($in['sources'] ?? array())), kop_vol_auto_allowed()));
    update_option('kop_vol_auto', array(
        'on'      => !empty($in['on']),
        'sources' => $sources,
        'agree'   => isset($in['agree']) ? (int) $in['agree'] : $cur['agree'],
        'decided' => isset($in['decided']) ? (int) $in['decided'] : $cur['decided'],
        'rate'    => isset($in['rate']) ? (int) $in['rate'] : $cur['rate'],
    ), false);
    return kop_vol_auto_settings();
}

/** Each volunteer's track record from the admin's decisions only: [id => {decided, agreed}]. */
function kop_vol_auto_track(array $vids) {
    $vids = array_values(array_unique(array_map('intval', $vids)));
    if (!$vids) return array();
    global $wpdb;
    $ph = implode(',', array_fill(0, count($vids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare('SELECT volunteer_id,
            SUM(CASE WHEN outcome IN (\'approve\', \'reject\') AND verdict IN (\'approve\', \'reject\') THEN 1 ELSE 0 END) AS decided,
            SUM(CASE WHEN outcome IN (\'approve\', \'reject\') AND verdict = outcome THEN 1 ELSE 0 END) AS agreed
        FROM ' . kop_vol_recs_table() . " WHERE volunteer_id IN ($ph) AND outcome_by <> %s GROUP BY volunteer_id",
        array_merge($vids, array(KOP_VOL_AUTO_BY))), ARRAY_A);
    $out = array();
    foreach ((array) $rows as $r) $out[(int) $r['volunteer_id']] = array('decided' => (int) $r['decided'], 'agreed' => (int) $r['agreed']);
    return $out;
}

function kop_vol_auto_trusted(array $track, array $s) {
    return $track['decided'] >= $s['decided'] && $track['agreed'] * 100 >= $s['rate'] * $track['decided'];
}

/** Has this item ever gone through on volunteers' word (undone or not)? */
function kop_vol_auto_went_before($source, $key) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_rinbox_log_table() . ' WHERE source = %s AND item_key = %s AND user_login = %s',
        (string) $source, (string) $key, KOP_VOL_AUTO_BY));
}

/**
 * Apply the item if the volunteers' calls on it meet the rules: the button's
 * label when it went through, else null. Never throws.
 */
function kop_vol_auto_try($source, $key) {
    try {
        $s = kop_vol_auto_settings();
        if (!$s['on'] || !in_array((string) $source, $s['sources'], true)) return null;
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT r.volunteer_id, r.verdict, v.revoked_at FROM ' . kop_vol_recs_table() . ' r JOIN ' . kop_vol_table()
            . " v ON v.id = r.volunteer_id WHERE r.source = %s AND r.item_key = %s AND r.outcome = ''", (string) $source, (string) $key), ARRAY_A);
        $verdicts = array_values(array_unique(array_column($rows, 'verdict')));
        if (count($verdicts) !== 1 || !in_array($verdicts[0], array('approve', 'reject'), true)) return null;
        $live = array();
        foreach ($rows as $r) if (empty($r['revoked_at'])) $live[] = (int) $r['volunteer_id'];
        if (count($live) < $s['agree']) return null;
        $trusted = 0;
        foreach (kop_vol_auto_track($live) as $t) if (kop_vol_auto_trusted($t, $s)) $trusted++;
        if ($trusted < $s['agree']) return null;
        if (isset(kop_vol_held_keys($source)[(string) $key])) return null;
        if (kop_vol_auto_went_before($source, $key)) return null;
        $item = kop_rinbox_get_item($source, $key);
        if (!kop_vol_actionable($source, $item)) return null;
        $pick = kop_volp_pick_action($item, $verdicts[0]);
        if (!$pick) return null;
        $GLOBALS['kop_rinbox_reviewer_as'] = KOP_VOL_AUTO_BY;
        try {
            kop_rinbox_apply_action($source, $key, $pick[0], $pick[1]);
        } finally {
            unset($GLOBALS['kop_rinbox_reviewer_as']);
        }
        return $pick[2];
    } catch (Throwable $e) {
        return null;
    }
}

/** The latest items that went through on volunteers' word, for the admin screen. */
function kop_vol_auto_recent($limit = 30) {
    global $wpdb;
    return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_rinbox_log_table() . ' WHERE user_login = %s ORDER BY id DESC LIMIT %d',
        KOP_VOL_AUTO_BY, (int) $limit), ARRAY_A);
}

/** The admin screen's section: the switch, its queues and rules, and what went through. */
function kop_vol_auto_admin_section($nonce, $inbox) {
    $s = kop_vol_auto_settings();
    $all = kop_rinbox_sources();
    ?>
    <h2 id="kop-vol-auto">Let agreed calls go through on their own</h2>
    <form method="post" style="max-width:900px">
        <?php echo $nonce; ?><input type="hidden" name="kop_vol_do" value="auto">
        <p style="font-size:15px;margin:8px 0">Now: <strong><?php echo $s['on'] ? 'On' : 'Off'; ?></strong>.
            <?php echo $s['on'] ? 'Agreed calls in the ticked queues go through without you.' : 'Every volunteer call waits for you.'; ?></p>
        <p><label style="font-size:15px"><input type="checkbox" name="kop_vol_auto_on" value="1" <?php checked($s['on']); ?>>
            <strong>On</strong>: when <?php echo (int) $s['agree']; ?> trusted volunteers give an item the same answer and nobody disagrees, press its button for me</label></p>
        <p>Trusted means at least
            <input type="number" name="kop_vol_auto_decided" value="<?php echo (int) $s['decided']; ?>" min="5" max="500" style="width:70px" aria-label="Calls you decided"> of their calls decided by you,
            agreeing with you <input type="number" name="kop_vol_auto_rate" value="<?php echo (int) $s['rate']; ?>" min="50" max="100" style="width:64px" aria-label="Agreement percent">% of the time or more.
            Volunteers needed: <input type="number" name="kop_vol_auto_agree" value="<?php echo (int) $s['agree']; ?>" min="2" max="5" style="width:56px" aria-label="Volunteers needed">.</p>
        <p style="margin-bottom:4px">In these queues (the others are never offered: publishing, named people, survivor material, a public status, merges, the submission queues):</p>
        <div style="columns:3 220px">
        <?php foreach (kop_vol_auto_allowed() as $key) :
            if (!isset($all[$key])) continue; ?>
            <label style="display:block;margin:2px 0"><input type="checkbox" name="kop_vol_auto_sources[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $s['sources'], true)); ?>>
                <?php echo esc_html($all[$key]['label']); ?></label>
        <?php endforeach; ?>
        </div>
        <p>Each one is in Recently done in the inbox with its Undo, filed under "volunteers". Once you undo one it is yours: it never goes through on its own again.
            Calls that went through this way do not count toward anyone's agreement rate.</p>
        <p><button type="submit" class="button button-primary">Save</button></p>
    </form>
    <?php
    $rows = kop_vol_auto_recent();
    echo '<h3>Gone through on their own</h3>';
    if (!$rows) {
        echo '<p>None yet.</p>';
        return;
    }
    $done = $inbox !== '' ? add_query_arg('type', '_done', $inbox) : '';
    echo '<p>Spot-check a few now and then. To take one back, use its Undo in ' . ($done ? '<a href="' . esc_url($done) . '">Recently done</a>' : 'Recently done') . '.</p>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>When</th><th>Queue</th><th>Item</th><th>Button</th><th>Result</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $url = $inbox !== '' ? add_query_arg('type', $r['source'], $inbox) : '';
        $title = $r['title'] !== '' ? $r['title'] : $r['item_key'];
        echo '<tr><td>' . esc_html(get_date_from_gmt($r['created_at'], 'M j g:i a')) . '</td><td>' . esc_html($all[$r['source']]['label'] ?? $r['source']) . '</td><td>'
            . ($url ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>' : esc_html($title)) . '</td><td>' . esc_html($r['action_label'] ?: $r['action']) . '</td><td>'
            . (!empty($r['undone_at']) ? 'Undone by ' . esc_html($r['undone_by']) : esc_html($r['message'])) . '</td></tr>';
    }
    echo '</tbody></table>';
}
