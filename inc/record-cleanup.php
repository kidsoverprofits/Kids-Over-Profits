<?php
/**
 * Record cleanup (docs/PLAN.md 3.36): the copies approvals and merges left on facility records, tidied by the
 * owner one record at a time at Review inbox > Record cleanup.
 *
 * Every Woodbury / HEAL / wiki / Fornits approval and every Merge Duplicates join used to add its own copy of
 * what a record already had: one person under two spellings ("James Omer", "Jim Omer"), a role carrying its
 * source and every title twice ("Founder, Owner, Founder (2006, Woodbury Reports)"), a name listed as the
 * record's own name or twice over ("X (AYA)", "Red River Academy (2006-2015)"), one death told by three
 * sources. The approve and merge paths now ask api/lib-record-dedupe.php first; this queue tidies what is
 * already there with the same rules.
 *
 * One card per record, each change a line to tick (kop_dd_tidy_doc() steps). "Tidy the ticked" writes them
 * in one save; "Leave as it is" files the unticked lines so they never come back. Undo puts the whole record
 * back as it was, while nobody has changed it since (option kop_rc_undo_<id>, not autoloaded).
 *
 * The cards are worked out from every record hourly (kop_rc_cron) and again for a record after each action;
 * option kop_record_cleanup_cards (not autoloaded) holds them.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/api/lib-record-dedupe.php';

/** "<count>|<last update>" of facilities_v2: the cards are worked out again when it moves. */
function kop_rc_fingerprint(PDO $pdo) {
    $r = $pdo->query('SELECT COUNT(*), MAX(updated_at) FROM facilities_v2')->fetch(PDO::FETCH_NUM);
    return (int) $r[0] . '|' . (string) $r[1];
}

/** The steps the owner left off: [facility id => [step key, ...]]. */
function kop_rc_left() {
    $v = get_option('kop_record_cleanup_left', array());
    return is_array($v) ? $v : array();
}

/** One record's card: {name, steps [{key, kind, text}]} without the steps left off, or null when nothing is left to tidy. */
function kop_rc_card_for(array $doc, $name, array $left) {
    list(, $steps) = kop_dd_tidy_doc($doc);
    $steps = array_values(array_filter($steps, function ($s) use ($left) { return !in_array($s['key'], $left, true); }));
    return $steps ? array('name' => (string) $name, 'steps' => $steps) : null;
}

/** Work out every card (or only $fid's) and store them. Returns the stored {fp, at, cards}. */
function kop_rc_build($fid = 0) {
    $pdo = kop_rinbox_pdo();
    $left = kop_rc_left();
    $store = get_option('kop_record_cleanup_cards', array());
    $store = is_array($store) && isset($store['cards']) ? $store : array('fp' => '', 'at' => '', 'cards' => array());
    if ($fid > 0) {
        $st = $pdo->prepare('SELECT id, name, json_data FROM facilities_v2 WHERE id = ?');
        $st->execute(array((int) $fid));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        unset($store['cards'][(int) $fid]);
    } else {
        $rows = $pdo->query('SELECT id, name, json_data FROM facilities_v2');
        $store['cards'] = array();
    }
    foreach ($rows as $r) {
        $doc = json_decode((string) $r['json_data'], true);
        if (!is_array($doc)) continue;
        $card = kop_rc_card_for($doc, $r['name'], (array) ($left[(int) $r['id']] ?? array()));
        if ($card) $store['cards'][(int) $r['id']] = $card;
    }
    if ($fid <= 0) $store['fp'] = kop_rc_fingerprint($pdo);
    $store['at'] = gmdate('c');
    update_option('kop_record_cleanup_cards', $store, false);
    return $store;
}

/** The waiting cards: [facility id => {name, steps}], worked out now when there are none stored yet. */
function kop_rc_cards() {
    $store = get_option('kop_record_cleanup_cards', null);
    if (!is_array($store) || !isset($store['cards'])) $store = kop_rc_build();
    return (array) $store['cards'];
}

/** Hourly: work the cards out again when any record changed. */
function kop_rc_cron() {
    try {
        $store = get_option('kop_record_cleanup_cards', array());
        if (!is_array($store) || ($store['fp'] ?? '') !== kop_rc_fingerprint(kop_rinbox_pdo())) kop_rc_build();
    } catch (Throwable $e) {
        error_log('kop_rc_cron: ' . $e->getMessage());
    }
}
add_action('kop_rc_cron', 'kop_rc_cron');
add_action('init', function () {
    if (!wp_next_scheduled('kop_rc_cron')) wp_schedule_event(time() + 600, 'hourly', 'kop_rc_cron');
});

/** The undo stack of one record: [{at, by, before (doc), after (md5 of the saved doc), lines}], newest last. */
function kop_rc_undo_stack($fid) {
    $v = get_option('kop_rc_undo_' . (int) $fid, array());
    return is_array($v) ? $v : array();
}

/** Records tidied here, newest first: [{fid, name, at, by, lines}] (option kop_record_cleanup_log, the last 500). */
function kop_rc_log() {
    $v = get_option('kop_record_cleanup_log', array());
    return is_array($v) ? $v : array();
}

function kop_rc_doc_hash(array $doc) {
    return md5((string) wp_json_encode($doc));
}

/**
 * Tidy $fid with the steps $keys (step keys from its card; the ones not named are left as they are).
 * Returns the message. Undo: kop_rc_undo().
 */
function kop_rc_apply($fid, array $keys, $reviewer) {
    $fid = (int) $fid;
    $keys = array_values(array_filter(array_map('strval', $keys), 'strlen'));
    if (!$keys) throw new RuntimeException('Tick at least one line.');
    $opts = kop_wbf_opts();
    $lines = array();
    $name = '';
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $keys, $opts, $reviewer, &$lines, &$name) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) throw new RuntimeException("Facility #{$fid} does not exist.");
        $before = $stored['doc'];
        $name = (string) ($before['identification']['name'] ?? '');
        list($doc, $steps) = kop_dd_tidy_doc($before, $keys);
        if (!$steps) throw new RuntimeException('Nothing to tidy any more: the record changed since. Reload the card.');
        // Straight through the store: the record is already in its current shape, and Undo must give back the very same row.
        kop_facility_save($doc, $opts);
        $after = kop_facility_load($fid, $opts);
        foreach ($steps as $s) $lines[] = $s['text'];
        $stack = kop_rc_undo_stack($fid);
        $stack[] = array('at' => gmdate('c'), 'by' => $reviewer, 'before' => $before, 'after' => kop_rc_doc_hash($after['doc']), 'lines' => $lines);
        update_option('kop_rc_undo_' . $fid, array_slice($stack, -10), false);
    });
    $log = kop_rc_log();
    array_unshift($log, array('fid' => $fid, 'name' => $name, 'at' => gmdate('c'), 'by' => $reviewer, 'lines' => $lines));
    update_option('kop_record_cleanup_log', array_slice($log, 0, 500), false);
    kop_rc_build($fid);
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array($fid));
    return 'Tidied ' . ($name !== '' ? $name : 'the record') . ': ' . count($lines) . ' change' . (count($lines) === 1 ? '' : 's') . '. Undo puts the record back as it was.';
}

/** Put $fid back as it was before its last tidy here, unless the record changed since. */
function kop_rc_undo($fid, $reviewer) {
    $fid = (int) $fid;
    $stack = kop_rc_undo_stack($fid);
    if (!$stack) throw new RuntimeException('Nothing to undo on this record.');
    $last = $stack[count($stack) - 1];
    $opts = kop_wbf_opts();
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $last, $opts) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) throw new RuntimeException("Facility #{$fid} does not exist.");
        if (kop_rc_doc_hash($stored['doc']) !== $last['after']) {
            throw new RuntimeException('The record changed after it was tidied, so Undo would throw that change away. Edit it by hand instead.');
        }
        kop_facility_save($last['before'], $opts);
    });
    array_pop($stack);
    update_option('kop_rc_undo_' . $fid, $stack, false);
    $log = kop_rc_log();
    foreach ($log as $i => $e) {
        if ((int) $e['fid'] === $fid && empty($e['undone'])) { $log[$i]['undone'] = gmdate('c'); $log[$i]['undone_by'] = $reviewer; break; }
    }
    update_option('kop_record_cleanup_log', $log, false);
    kop_rc_build($fid);
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array($fid));
    return 'Put the record back as it was before the tidy.';
}

/** "Leave as it is": the steps $keys (or every step on the card) never come back for $fid. Undo: kop_rc_unleave(). */
function kop_rc_leave($fid, array $keys) {
    $fid = (int) $fid;
    $cards = kop_rc_cards();
    if (!$keys) $keys = array_column((array) ($cards[$fid]['steps'] ?? array()), 'key');
    $left = kop_rc_left();
    $left[$fid] = array_values(array_unique(array_merge((array) ($left[$fid] ?? array()), $keys)));
    update_option('kop_record_cleanup_left', $left, false);
    kop_rc_build($fid);
    return 'Left the record as it is; these lines will not come back.';
}

function kop_rc_unleave($fid) {
    $left = kop_rc_left();
    unset($left[(int) $fid]);
    update_option('kop_record_cleanup_left', $left, false);
    kop_rc_build((int) $fid);
    return 'The lines left off wait for review again.';
}
