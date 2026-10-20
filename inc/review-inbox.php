<?php
/**
 * One review inbox for every queue that waits on a person.
 *
 * The Submissions Review page (templates/page-admin-submissions.php) lists
 * news, data, wiki, lawsuits and legislation with its own screen. Every other
 * queue (closure reports, facilities from news, Drive Docs, Fornits, Woodbury,
 * inspection highlights, merges, map years, ...) is a source registered here,
 * drawn on the same page by js/review-inbox.js with the same controls: edit
 * the name and details, change the category, tags, move it to another queue,
 * fill empty fields with AI, and the queue's own actions (with its Undo).
 * Cards also get the card add-ons switched on for their queue ("Home of a
 * program", "File under a young adult program", ...; kop_rinbox_addon(),
 * below), answered before the queue's 'act'. The owner switches each add-on
 * on or off per queue at KOP Tools > Card Add-ons (inc/review-inbox-addons.php).
 *
 * A source is an adapter in inc/review-inbox/<name>.php that registers with
 * kop_rinbox_register('<name>', fn () => spec or null). The spec is built on
 * first use, when every queue's file has loaded (null: that queue is not
 * installed here). Other code can add or change sources through the
 * 'kop_review_inbox_sources' filter. It calls the queue's own functions
 * (kop_closure_apply(), kop_gdl_apply(), ...), so approving here does exactly
 * what the queue's old screen did. Shape:
 *
 *   'label'    tab name           'group'  heading the tab sits under
 *   'views'    [view => label]; the first is the waiting view
 *   'count'    fn(): int          items waiting
 *   'list'     fn(array $q): ['items' => [...], 'total' => int]
 *              $q: view, search, offset, limit
 *   'get'      fn(string $key): ?item
 *   'act'      fn(string $key, string $action, array $params): ['message' => ...]
 *              (throws on failure)
 *   'save'     fn(string $key, array $fields): ['message' => ...]   optional
 *   'no_ai'    true: no "Fill empty fields with AI" for the 'save' fields   optional
 *   'ai_fill'  fn(string $key, string $text): ['filled' => [...]]   optional;
 *              without it the generic filler reads the item's link and fills
 *              its empty editable fields through save. $text is what the
 *              reviewer pasted in "Paste text for the AI", read instead of the link
 *   'tags_get' / 'tags_set'  fn(key) / fn(key, tags)   optional; else the
 *              shared {prefix}kop_review_tags table
 *   'origins'  fn(array $q): [{key, label, count}]   optional; the "Came from"
 *              filter (scraper, import, extension, people...); 'list' then
 *              gets the chosen key as $q['origin']
 *   'tools'    [{id, label, help, style, confirm, params [field...]}]  optional;
 *              buttons over the queue that are not about one item (scan now,
 *              read now, add every sure match for a facility)
 *   'tool'     fn(string $id, array $params): ['message' => ...]
 *   'tool_url' the queue's full screen, for what this one does not do
 *   'native'   true for the five types the page already draws itself
 *   'filters'  [{name, label, options {value: label}}]  optional; more
 *              dropdowns beside the search (kind, category, importance...);
 *              'list' gets the chosen values as $q['filters'][name] (only
 *              values that are one of the options; none chosen: not set)
 *   'view_counts' fn(array $q): [view => int]   optional; shown on the view
 *              tabs, read with each page of items ($q as for 'list')
 *   'on_file'  fn(): [key => {label, url}]  optional; waiting items that are
 *              already in our records (the link is on the facility, the article
 *              is listed there already...). They leave the waiting view and its
 *              count and are listed under an "Already on file" view instead,
 *              each card naming the record that holds it, to reject in a batch
 *   'conflicts' fn(): [key => {text, record, item, what, title, facility_id}]
 *              optional; waiting items that disagree with their record. They
 *              leave the waiting view and its count for the Conflicts section
 *              (inc/review-inbox/conflicts.php), which settles them through
 *   'resolve'  fn(key, how ('keep' | 'use' | 'edit'), typed value): message
 *   'on_file_in_list' true when 'list', 'view_counts' and 'count' leave out
 *              on-file and conflict items themselves (a big queue filtering in SQL)
 *   'lookup'   fn(string $name, string $q): [{value, label}]  optional; a
 *              text field or param with 'lookup' => name suggests values as
 *              the reviewer types (a company "c12", a consultant...)
 *   'addons'   [add-on id]  optional; card add-ons this queue has unless the
 *              owner switches them off (an add-on that is on everywhere needs
 *              no entry here)
 *
 * An item: key, title, subtitle, url, text, created, status, status_label,
 * facility {id, name, url}, fields [{name, label, type (text, textarea, list,
 * select, number, facility, facilities (several ids), checkbox), value,
 * options {value: label}, category}], details [{label, value}] (facts shown on
 * the card), compare {heads: [..], rows: [{label, values: [..]}]} (two records
 * side by side), preview {label, url} (a PDF or page shown in the card),
 * conflict (what disagrees with the record or another source: shown as a
 * "Conflict" mark on the card, which then never starts ticked),
 * actions [{id, label, style (approve, reject, neutral, undo), confirm,
 * params [field...]}], moves [{id, label}], links [{label, url}],
 * selected (true: the card's "select" box starts ticked, a sure match).
 * An action with 'with_fields' => true also gets the card editor's fields as
 * they are now, saved or not, as $params['fields'] (approve the text as edited).
 * A param with 'optional' => true may be left empty: the action is still
 * offered for the selected cards, and each card sends what its own inputs
 * say at the time.
 *
 * A param that picks a company, program, consultant... (or names a new one)
 * is kop_record_field_param() (inc/record-field.php): type 'record' with
 * 'kinds' and 'create'; the action turns its value into a record with
 * kop_record_field_resolve() and its Undo calls kop_record_field_unmake().
 *
 * An action may carry 'help': one sentence on what clicking it does, shown
 * under the Approve / Reject buttons so the reviewer knows what they approve.
 *
 * REST (manage_options, wp_rest nonce): kop/v1/review-inbox/{sources, items,
 * act, save, tags, ai}; inc/review-inbox-log.php adds {log, undo, hold, held,
 * preview}: Recently done with Undo, snooze / assign, and link previews.
 * Every item carries 'recs': what volunteer reviewers recommended
 * (inc/review-volunteers.php; held?which=volunteers lists those items).
 * Tested by scripts/test-review-inbox.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_REVIEW_INBOX_DB_VERSION = '3'; // 3: kop_review_holds.research

/** Add a source: $build returns its spec, or null when its queue is not loaded. */
function kop_rinbox_register($key, callable $build) {
    $GLOBALS['kop_rinbox_builders'][$key] = $build;
}

/*
 * Card add-ons: an action any queue's cards can have, written once.
 *
 *   kop_rinbox_addon('<id>', array(
 *       'label'   'Home of a program'   (the Card Add-ons screen)
 *       'help'    one sentence: what it does on a card   (the same screen)
 *       'actions' [action ids it answers, its Undo's included]
 *       'card'    fn(string $source, array $item): action or null
 *                 (null: not for this card, e.g. no facility on it)
 *       'act'     fn(string $source, string $key, string $action, array $params): result
 *                 (as a queue's 'act'; 'undo' => {action, params} for Recently done)
 *       'never'   [queues it can never be on: about something else, or doing it their own way]
 *       'default' true: on for every other queue until switched off;
 *                 false: only where a queue lists it in 'addons' or the owner switches it on
 *   ));
 *
 * kop_rinbox_finish_items() asks each add-on that is on for the queue for its
 * action; kop_rinbox_cross_act() answers its actions before the queue's own
 * 'act' (always, so an Undo still works after it is switched off).
 */
function kop_rinbox_addon($id, array $spec) {
    $GLOBALS['kop_rinbox_addons'][$id] = $spec + array('label' => $id, 'help' => '', 'actions' => array(),
        'card' => null, 'act' => null, 'never' => array(), 'default' => true);
}

/** Every add-on, by id, in the order they were added. */
function kop_rinbox_addons() {
    return (array) ($GLOBALS['kop_rinbox_addons'] ?? array());
}

/** The owner's choices: [add-on id => [queue => true|false]]. */
function kop_rinbox_addon_choices() {
    $v = get_option('kop_review_addons', array());
    return is_array($v) ? $v : array();
}

/** Can the add-on ever be on this queue? */
function kop_rinbox_addon_allowed($id, $source) {
    $a = kop_rinbox_addons()[$id] ?? null;
    if (!$a) return false;
    $never = is_callable($a['never']) ? (array) call_user_func($a['never']) : (array) $a['never'];
    return !in_array($source, $never, true);
}

/** Is the add-on on for this queue: the owner's choice, else the queue's 'addons', else its default. */
function kop_rinbox_addon_on($id, $source) {
    if (!kop_rinbox_addon_allowed($id, $source)) return false;
    $choices = kop_rinbox_addon_choices();
    if (isset($choices[$id][$source])) return (bool) $choices[$id][$source];
    return kop_rinbox_addon_default($id, $source);
}

// Where "Move to" can send an item (news, lawsuits, legislation, Industry PR, a facility's website or resources).
require_once __DIR__ . '/review-destinations.php';
// Filing an item on any kind of record (company, consultant, young adult program...), with Undo
require_once __DIR__ . '/review-record-file.php';
// Recently done (with Undo), snooze / assign, link previews.
require_once __DIR__ . '/review-inbox-log.php';
// Volunteers recommend approve / reject through a personal link (/volunteer-review/).
require_once __DIR__ . '/review-volunteers.php';
// KOP Tools > Card Add-ons: which add-on is on for which queue.
require_once __DIR__ . '/review-inbox-addons.php';
// KOP Tools > Card Fields: what each queue's cards show, and what kinds of record its pickers offer
require_once __DIR__ . '/review-inbox-fields.php';

foreach (glob(__DIR__ . '/review-inbox/*.php') ?: array() as $kop_rinbox_file) {
    require_once $kop_rinbox_file;
}
unset($kop_rinbox_file);

/** Every registered source, keyed by name, in the order they were added. */
function kop_rinbox_sources($rebuild = false) {
    static $sources = null;
    if ($sources === null || $rebuild) {
        $built = array();
        foreach ((array) ($GLOBALS['kop_rinbox_builders'] ?? array()) as $key => $build) {
            $spec = call_user_func($build);
            if (is_array($spec)) $built[$key] = $spec;
        }
        $sources = array();
        foreach ((array) apply_filters('kop_review_inbox_sources', $built) as $key => $src) {
            if (!is_array($src) || empty($src['label'])) continue;
            $src['key'] = $key;
            $src += array('group' => 'Other', 'views' => array('pending' => 'Waiting'), 'native' => false, 'tool_url' => '');
            // Cards filed under a young adult program wait under "Already on file" (inc/review-inbox/_ya-file-action.php).
            if (function_exists('kop_rinbox_ya_filed_wrap')) $src = kop_rinbox_ya_filed_wrap($key, $src);
            if (!empty($src['on_file'])) {
                // "Already on file" right after the waiting view.
                $views = $src['views'];
                $first = array_slice($views, 0, 1, true);
                $src['views'] = $first + array('on_file' => 'Already on file') + array_slice($views, 1, null, true);
            }
            $sources[$key] = $src;
        }
    }
    return $sources;
}

function kop_rinbox_source($key) {
    $all = kop_rinbox_sources();
    if (!isset($all[$key])) {
        throw new InvalidArgumentException('Unknown queue: ' . $key);
    }
    return $all[$key];
}

/** Who is reviewing, as the queues record it. */
function kop_rinbox_reviewer() {
    // Volunteers' agreed calls (inc/volunteer-auto.php) are filed under their own name.
    if (!empty($GLOBALS['kop_rinbox_reviewer_as'])) return (string) $GLOBALS['kop_rinbox_reviewer_as'];
    $u = wp_get_current_user();
    return $u && $u->exists() ? (string) $u->user_login : 'admin';
}

/** The records DB (news, lawsuits, closure reports, facilities_v2...). */
function kop_rinbox_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('The records database is not reachable.');
    }
    return $pdo;
}

/** [value => label] options from a plain list. */
function kop_rinbox_options(array $values) {
    $out = array();
    foreach ($values as $k => $v) {
        if (is_int($k)) $out[(string) $v] = ucfirst(str_replace('_', ' ', (string) $v));
        else $out[(string) $k] = (string) $v;
    }
    return $out;
}

/** A facility reference for an item, with its name and page. */
function kop_rinbox_facility($fid, PDO $pdo = null) {
    $fid = (int) $fid;
    if ($fid <= 0) return null;
    static $cache = array();
    if (!isset($cache[$fid])) {
        $name = '';
        try {
            $pdo = $pdo ?: kop_rinbox_pdo();
            $st = $pdo->prepare('SELECT name, unique_name, city, state, country FROM facilities_v2 WHERE id = ?');
            $st->execute(array($fid));
            if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
                $place = trim(implode(', ', array_filter(array($f['city'], $f['state'] ?: $f['country']))));
                $name = ($f['name'] ?: $f['unique_name']) . ($place !== '' ? ' (' . $place . ')' : '');
            }
        } catch (Throwable $e) {
            $name = '';
        }
        $cache[$fid] = array(
            'id'   => $fid,
            'name' => $name !== '' ? $name : 'facility #' . $fid,
            'url'  => function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '',
        );
    }
    return $cache[$fid];
}

/** Text trimmed for a card. */
function kop_rinbox_excerpt($text, $max = 400) {
    $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

/* ---- Tags ----------------------------------------------------------------- */

function kop_rinbox_tags_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_review_tags';
}

function kop_rinbox_ensure_tables() {
    if (get_option('kop_review_inbox_db') === KOP_REVIEW_INBOX_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = kop_rinbox_tags_table();
    dbDelta("CREATE TABLE $t (
        source VARCHAR(40) NOT NULL,
        item_key VARCHAR(191) NOT NULL,
        tag VARCHAR(80) NOT NULL,
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (source, item_key, tag),
        KEY tag (tag)
    ) " . $wpdb->get_charset_collate() . ';');
    foreach (kop_rinbox_log_tables_sql($wpdb->get_charset_collate()) as $sql) dbDelta($sql);
    update_option('kop_review_inbox_db', KOP_REVIEW_INBOX_DB_VERSION);
}

/** A tag as stored: trimmed, single spaces, lower case, at most 80 characters. */
function kop_rinbox_clean_tag($tag) {
    $tag = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $tag)));
    return mb_substr($tag, 0, 80);
}

function kop_rinbox_clean_tags($tags) {
    if (is_string($tags)) $tags = preg_split('/[,\n]+/', $tags);
    $out = array();
    foreach ((array) $tags as $t) {
        $t = kop_rinbox_clean_tag($t);
        if ($t !== '') $out[$t] = true;
    }
    $out = array_keys($out);
    sort($out);
    return $out;
}

/** Tags as typed: trimmed, single spaces, no repeats (any case), case kept. */
function kop_rinbox_trim_tags($tags) {
    if (is_string($tags)) $tags = preg_split('/[,\n]+/', $tags);
    $out = array();
    foreach ((array) $tags as $t) {
        $t = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $t)), 0, 80);
        if ($t !== '' && !isset($out[mb_strtolower($t)])) $out[mb_strtolower($t)] = $t;
    }
    return array_values($out);
}

/** Tags of many items of one source: [key => [tag, ...]]. */
function kop_rinbox_tags_for($source, array $keys) {
    $src = kop_rinbox_source($source);
    $out = array();
    if (!empty($src['tags_get'])) {
        foreach ($keys as $k) $out[$k] = kop_rinbox_trim_tags(call_user_func($src['tags_get'], (string) $k));
        return $out;
    }
    if (!$keys) return $out;
    kop_rinbox_ensure_tables();
    global $wpdb;
    $t = kop_rinbox_tags_table();
    $ph = implode(',', array_fill(0, count($keys), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT item_key, tag FROM $t WHERE source = %s AND item_key IN ($ph) ORDER BY tag",
        array_merge(array($source), array_map('strval', $keys))
    ), ARRAY_A);
    foreach ((array) $rows as $r) $out[$r['item_key']][] = $r['tag'];
    return $out;
}

function kop_rinbox_set_tags($source, $key, $tags) {
    $src = kop_rinbox_source($source);
    // A queue that keeps its own tags keeps their case (news tags read "Juvenile Justice").
    if (!empty($src['tags_set'])) {
        $saved = call_user_func($src['tags_set'], (string) $key, kop_rinbox_trim_tags($tags));
        return kop_rinbox_trim_tags(is_array($saved) ? $saved : call_user_func($src['tags_get'], (string) $key));
    }
    $tags = kop_rinbox_clean_tags($tags);
    kop_rinbox_ensure_tables();
    global $wpdb;
    $t = kop_rinbox_tags_table();
    $wpdb->delete($t, array('source' => $source, 'item_key' => (string) $key));
    foreach ($tags as $tag) {
        $wpdb->insert($t, array(
            'source' => $source, 'item_key' => (string) $key, 'tag' => $tag,
            'created_by' => kop_rinbox_reviewer(), 'created_at' => current_time('mysql', true),
        ));
    }
    return $tags;
}

/** Every tag in use (shared table, plus what sources with their own tags report). */
function kop_rinbox_known_tags() {
    $tags = array();
    try {
        kop_rinbox_ensure_tables();
        global $wpdb;
        foreach ((array) $wpdb->get_col('SELECT DISTINCT tag FROM ' . kop_rinbox_tags_table() . ' ORDER BY tag LIMIT 500') as $t) $tags[$t] = true;
    } catch (Throwable $e) {
        // A missing table only means no suggestions.
    }
    foreach (kop_rinbox_trim_tags((array) apply_filters('kop_review_inbox_known_tags', array())) as $t) $tags[$t] = true;
    $tags = array_keys($tags);
    sort($tags);
    return $tags;
}

/* ---- Items ---------------------------------------------------------------- */

/** Fill in what every item needs (tags, defaults) for the browser. */
function kop_rinbox_finish_items($source, array $items) {
    $keys = array();
    foreach ($items as $it) $keys[] = (string) $it['key'];
    $tags = kop_rinbox_tags_for($source, $keys);
    // What volunteer reviewers recommended (inc/review-volunteers.php).
    $recs = function_exists('kop_vol_recs_for') ? kop_vol_recs_for($source, $keys) : array();
    $seen = array();
    foreach ($items as &$it) {
        $it += array('subtitle' => '', 'url' => '', 'text' => '', 'created' => '', 'status' => '', 'status_label' => '',
            'facility' => null, 'fields' => array(), 'actions' => array(), 'moves' => array(), 'links' => array(),
            'details' => array(), 'compare' => null, 'preview' => null, 'selected' => false, 'conflict' => '');
        $it['key'] = (string) $it['key'];
        // A conflict is always marked, and never ticked for a bulk Add.
        $it['conflict'] = trim(implode(' ', array_filter(array_map('trim', array_map('strval', (array) $it['conflict'])), 'strlen')));
        if ($it['conflict'] !== '') $it['selected'] = false;
        $it['tags'] = $tags[$it['key']] ?? array();
        $it['hold'] = kop_rinbox_hold_info($source, $it['key']);
        $it['recs'] = $recs[$it['key']] ?? array();
        // The card add-ons switched on for this queue ("Home of a program", "File under a young adult program", ...).
        foreach (kop_rinbox_addons() as $aid => $a) {
            if (!is_callable($a['card']) || !kop_rinbox_addon_on($aid, $source)) continue;
            try {
                if ($act = call_user_func($a['card'], $source, $it)) $it['actions'][] = $act;
            } catch (Throwable $e) {
                // The card still draws without it.
            }
        }
        // The owner's Card Fields choices: hidden fields and lines, the kinds of record pickers offer.
        if (function_exists('kop_rinbox_card_fields_apply')) $it = kop_rinbox_card_fields_apply($source, $it, $seen);
    }
    unset($it);
    if (function_exists('kop_rinbox_card_fields_note')) kop_rinbox_card_fields_note($seen);
    return $items;
}

/** [key => {label, url}]: the source's waiting items already in our records ('on_file'), once a request. */
function kop_rinbox_on_file_keys($source) {
    static $memo = array();
    if (isset($memo[$source])) return $memo[$source];
    $src = kop_rinbox_sources()[$source] ?? array();
    $out = array();
    if (!empty($src['on_file'])) {
        try {
            foreach ((array) call_user_func($src['on_file']) as $key => $info) $out[(string) $key] = (array) $info;
        } catch (Throwable $e) {
            // Without the check they simply stay in the waiting view.
        }
    }
    return $memo[$source] = $out;
}

/** [key => {text, record, item, what, title, facility_id}]: the source's waiting items in conflict ('conflicts'), once a request. */
function kop_rinbox_conflict_keys($source) {
    // Kept for the request; a settled conflict clears it (kop_rinbox_conflicts_caches()).
    $memo = &$GLOBALS['kop_rinbox_conflict_memo'];
    if (isset($memo[$source])) return $memo[$source];
    $src = kop_rinbox_sources()[$source] ?? array();
    $out = array();
    if (!empty($src['conflicts'])) {
        try {
            foreach ((array) call_user_func($src['conflicts']) as $key => $info) $out[(string) $key] = (array) $info;
        } catch (Throwable $e) {
            // Without the check they stay in the waiting view, still marked on their cards.
        }
    }
    return $memo[$source] = $out;
}

/** The "Already on file" view: the items themselves, each saying which record holds it. */
function kop_rinbox_on_file_list(array $src, $source, array $q) {
    $found = kop_rinbox_on_file_keys($source);
    $items = array();
    foreach (array_slice($found, $q['offset'], $q['limit'], true) as $key => $info) {
        $it = !empty($src['get']) ? call_user_func($src['get'], (string) $key) : null;
        if (!$it) continue;
        $it['details'] = array_merge(array(array('label' => 'Already on file', 'value' => (string) ($info['label'] ?? ''))), (array) ($it['details'] ?? array()));
        if (!empty($info['url'])) $it['links'] = array_merge(array(array('label' => 'Where it is', 'url' => (string) $info['url'])), (array) ($it['links'] ?? array()));
        $items[] = $it;
    }
    return array('items' => $items, 'total' => count($found), 'next_offset' => $q['offset'] + $q['limit']);
}

function kop_rinbox_get_item($source, $key) {
    $src = kop_rinbox_source($source);
    if (empty($src['get'])) throw new RuntimeException('This queue cannot open one item.');
    $item = call_user_func($src['get'], (string) $key);
    if (!$item) throw new RuntimeException('That item is gone (someone may have handled it).');
    $items = kop_rinbox_finish_items($source, array($item));
    return $items[0];
}

/*
 * Waiting counts. Some queues count by scanning (Inspection Links ~10 s, Merge
 * Duplicates ~4 s cold), so the page never waits on them: it draws from the
 * last counts saved in the kop_review_inbox_counts option and asks for the
 * stale ones afterwards (review-inbox/counts, a few queues a request), and a
 * 10-minute cron keeps them warm. An action marks every count stale; the
 * number it had still shows until the new one is in.
 */
const KOP_RINBOX_COUNTS_FRESH = 300;

/** [source => ['n' => count|null, 'at' => unix time it was counted, 0 = stale]] */
function kop_rinbox_counts_stored() {
    $v = get_option('kop_review_inbox_counts', array());
    return is_array($v) ? $v : array();
}

/** One source's waiting count, counted now (null when its count fails). */
function kop_rinbox_count_one($key) {
    $src = kop_rinbox_sources()[$key] ?? null;
    if (!$src || empty($src['count'])) return null;
    try {
        $n = (int) call_user_func($src['count']);
        // What is already on file, or in conflict, waits under its own view or the Conflicts section, not here.
        if (empty($src['on_file_in_list'])) {
            $n = max(0, $n - count(kop_rinbox_on_file_keys($key)) - count(kop_rinbox_conflict_keys($key)));
        }
        return $n;
    } catch (Throwable $e) {
        return null;
    }
}

/** Count these sources now (all when null) and save them; returns [source => count]. */
function kop_rinbox_counts_refresh(array $keys = null) {
    $all = kop_rinbox_sources();
    $keys = $keys === null ? array_keys($all) : array_values(array_intersect($keys, array_keys($all)));
    $got = array();
    foreach ($keys as $key) {
        if (empty($all[$key]['count'])) continue;
        $got[$key] = kop_rinbox_count_one($key);
    }
    // Read again just before writing: another request may have saved other queues meanwhile.
    $stored = kop_rinbox_counts_stored();
    foreach ($got as $key => $n) $stored[$key] = array('n' => $n, 'at' => time());
    update_option('kop_review_inbox_counts', $stored, false);
    return $got;
}

/** Waiting counts for every source: [source => count|null]; $fresh counts them all now. */
function kop_rinbox_counts($fresh = false) {
    if ($fresh) kop_rinbox_counts_refresh();
    $out = array();
    foreach (kop_rinbox_counts_stored() as $key => $c) $out[$key] = $c['n'] ?? null;
    return $out;
}

/** The sources whose saved count is missing or older than KOP_RINBOX_COUNTS_FRESH. */
function kop_rinbox_counts_stale() {
    $stored = kop_rinbox_counts_stored();
    $out = array();
    foreach (kop_rinbox_sources() as $key => $src) {
        if (empty($src['count'])) continue;
        if (time() - (int) ($stored[$key]['at'] ?? 0) >= KOP_RINBOX_COUNTS_FRESH) $out[] = $key;
    }
    return $out;
}

/** Something was approved, rejected or undone: every count is stale (kept on show until recounted). */
function kop_rinbox_flush_counts() {
    $stored = kop_rinbox_counts_stored();
    if (!$stored) return;
    foreach ($stored as &$c) $c['at'] = 0;
    unset($c);
    update_option('kop_review_inbox_counts', $stored, false);
}

/** A count as the tabs show it: snoozed items and items handed to someone else are not waiting on me. */
function kop_rinbox_count_for_me($key, $count) {
    return $count === null ? null : max(0, (int) $count - count(kop_rinbox_hidden_keys($key)));
}

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['kop_ten_minutes'])) {
        $schedules['kop_ten_minutes'] = array('interval' => 600, 'display' => 'Every 10 minutes (KOP)');
    }
    return $schedules;
});

add_action('init', function () {
    if (!wp_next_scheduled('kop_review_inbox_counts_cron')) {
        wp_schedule_event(time() + 120, 'kop_ten_minutes', 'kop_review_inbox_counts_cron');
    }
});

add_action('kop_review_inbox_counts_cron', function () {
    $stale = kop_rinbox_counts_stale();
    if ($stale) kop_rinbox_counts_refresh($stale);
});

/* ---- AI ------------------------------------------------------------------- */

/** True when a field value is empty (blank text, empty list). */
function kop_rinbox_is_empty($v) {
    if (is_array($v)) return !array_filter($v, function ($x) { return trim((string) $x) !== ''; });
    return trim((string) $v) === '';
}

/**
 * Fill an item's empty editable fields with AI: the item's own text plus the
 * page or PDF its link points to go to kop_ai_generate_alternating() (Groq and
 * Gemini in turn), which answers JSON for those fields only. A select field
 * only takes one of its options. Saves through the source's save.
 */
function kop_rinbox_ai_fill_generic($source, $key, $text = '') {
    $src = kop_rinbox_source($source);
    if (empty($src['save'])) throw new RuntimeException('This queue has no fields to fill.');
    $item = kop_rinbox_get_item($source, $key);
    $want = array();
    foreach ($item['fields'] as $f) {
        if (in_array($f['type'] ?? 'text', array('facility', 'number'), true) || !empty($f['readonly'])) continue;
        // A name that is only the item's own address (a Drive Docs link with no label) counts as empty.
        $bare = !is_array($f['value'] ?? '') && $item['url'] !== '' && trim((string) ($f['value'] ?? '')) === $item['url'];
        if ($bare || kop_rinbox_is_empty($f['value'] ?? '')) $want[$f['name']] = $f;
    }
    if (!$want) return array('filled' => array(), 'message' => 'Every field already has something in it.');

    $context = trim($item['title'] . "\n" . $item['subtitle'] . "\n" . $item['text']);
    foreach ($item['fields'] as $f) {
        if (!kop_rinbox_is_empty($f['value'] ?? '')) {
            $v = is_array($f['value']) ? implode('; ', $f['value']) : (string) $f['value'];
            $context .= "\n" . $f['label'] . ': ' . mb_substr($v, 0, 600);
        }
    }
    // Pasted text stands in for the link (a Drive file or paywall the reader cannot open).
    $page = trim((string) $text);
    if ($page !== '') {
        $context .= "\n\nText the reviewer pasted:\n" . mb_substr($page, 0, 12000);
    } elseif ($item['url'] !== '' && function_exists('kop_rinbox_load_enrich') && kop_rinbox_load_enrich()) {
        try {
            $page = (string) kop_enrich_document_text($item['url']);
        } catch (Throwable $e) {
            $page = '';
        }
        if ($page !== '') $context .= "\n\nText of " . $item['url'] . ":\n" . mb_substr($page, 0, 12000);
    }

    $spec = array();
    foreach ($want as $name => $f) {
        $line = '- "' . $name . '": ' . $f['label'];
        $type = $f['type'] ?? 'text';
        if ($type === 'select' && !empty($f['options'])) {
            $line .= ' (exactly one of: ' . implode(', ', array_map('strval', array_keys($f['options']))) . ')';
        } elseif ($type === 'list') {
            $line .= ' (a JSON list of short strings)';
        } elseif ($type === 'textarea') {
            $line .= ' (a few plain sentences)';
        }
        $spec[] = $line;
    }
    $prompt = "You fill in missing fields of a record for Kids Over Profits, a site documenting abuse in the troubled teen industry.\n"
        . "Use only facts stated in the material below. Never guess: leave a field out when the material does not say it.\n"
        . "Write neutrally and factually. Never blame a young person for what was done to them.\n"
        . "Answer with one JSON object and nothing else, with only these keys:\n" . implode("\n", $spec)
        . "\n\nMaterial:\n" . $context;

    kop_rinbox_load_ai();
    $raw = kop_ai_generate_alternating($prompt, array('maxTokens' => 1500, 'temperature' => 0.1));
    $answer = kop_ai_extract_json($raw);
    if (!is_array($answer)) throw new RuntimeException('The AI answer was not readable. Try again.');

    $fields = array();
    foreach ($want as $name => $f) {
        if (!array_key_exists($name, $answer)) continue;
        $v = $answer[$name];
        $type = $f['type'] ?? 'text';
        if ($type === 'select') {
            $v = (string) $v;
            if (empty($f['options']) || !array_key_exists($v, $f['options'])) continue;
        } elseif ($type === 'list') {
            $v = array_values(array_filter(array_map(function ($x) { return trim((string) $x); }, is_array($v) ? $v : preg_split('/[\n;]+/', (string) $v)), 'strlen'));
        } else {
            $v = is_array($v) ? implode('; ', $v) : trim((string) $v);
        }
        if (kop_rinbox_is_empty($v)) continue;
        $fields[$name] = $v;
    }
    if (!$fields) return array('filled' => array(), 'message' => 'The AI found nothing to add from this item and its link.');
    call_user_func($src['save'], (string) $key, $fields);
    return array('filled' => array_keys($fields), 'message' => 'Filled: ' . implode(', ', array_map(function ($n) use ($want) { return $want[$n]['label']; }, array_keys($fields))) . '.');
}

function kop_rinbox_load_ai() {
    // kop_seed_pdo() loads api/config.php, which reads the AI keys from .env.
    kop_rinbox_pdo();
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
}

function kop_rinbox_load_enrich() {
    if (!function_exists('kop_enrich_document_text')) {
        $path = get_stylesheet_directory() . '/api/lib-record-enrich.php';
        if (!file_exists($path)) return false;
        require_once $path;
    }
    return true;
}

/* ---- REST ----------------------------------------------------------------- */

add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };
    $routes = array(
        'sources' => array('GET', 'kop_rinbox_rest_sources'),
        'counts'  => array('GET', 'kop_rinbox_rest_counts'),
        'items'   => array('GET', 'kop_rinbox_rest_items'),
        'item'    => array('GET', 'kop_rinbox_rest_item'),
        'origins' => array('GET', 'kop_rinbox_rest_origins'),
        'act'     => array('POST', 'kop_rinbox_rest_act'),
        'save'    => array('POST', 'kop_rinbox_rest_save'),
        'tags'    => array('POST', 'kop_rinbox_rest_tags'),
        'ai'      => array('POST', 'kop_rinbox_rest_ai'),
        'tool'    => array('POST', 'kop_rinbox_rest_tool'),
        'lookup'  => array('GET', 'kop_rinbox_rest_lookup'),
        'all-tools' => array('GET', 'kop_rinbox_rest_all_tools'),
    );
    foreach ($routes as $path => $r) {
        register_rest_route('kop/v1', '/review-inbox/' . $path, array(
            'methods' => $r[0], 'callback' => $r[1], 'permission_callback' => $perm,
        ));
    }
});

/** Run a REST handler, turning a thrown error into a 400 with its message. */
function kop_rinbox_rest(callable $fn) {
    try {
        return new WP_REST_Response(call_user_func($fn), 200);
    } catch (Throwable $e) {
        return new WP_REST_Response(array('error' => $e->getMessage()), 400);
    }
}

function kop_rinbox_rest_sources(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        // The saved counts only: counting here kept the page blank for up to half a minute.
        $counts = kop_rinbox_counts((bool) $req->get_param('fresh'));
        $out = array();
        foreach (kop_rinbox_sources() as $key => $src) {
            $out[] = array(
                'key' => $key, 'label' => $src['label'], 'group' => $src['group'], 'views' => $src['views'],
                'count' => kop_rinbox_count_for_me($key, $counts[$key] ?? null), 'native' => (bool) $src['native'], 'tool_url' => (string) $src['tool_url'],
                'help' => (string) ($src['help'] ?? ''), 'can_save' => !empty($src['save']),
                'can_ai' => (!empty($src['save']) && empty($src['no_ai'])) || !empty($src['ai_fill']),
                'has_origins' => !empty($src['origins']),
                'tools' => array_values((array) ($src['tools'] ?? array())),
                'filters' => array_values((array) ($src['filters'] ?? array())),
            );
        }
        return array('sources' => $out, 'stale' => kop_rinbox_counts_stale(), 'tags' => kop_rinbox_known_tags(), 'admins' => kop_rinbox_admins(),
            'me' => get_current_user_id(), 'held' => kop_rinbox_held_counts() + array('volunteers' => function_exists('kop_vol_open_count') ? kop_vol_open_count() : 0));
    });
}

/** Count the named sources now (?sources=a,b): [source => count], as the tabs show it. */
function kop_rinbox_rest_counts(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $keys = array_slice(array_filter(array_map('sanitize_key', explode(',', (string) $req->get_param('sources'))), 'strlen'), 0, 50);
        $out = array();
        foreach (kop_rinbox_counts_refresh($keys) as $key => $n) $out[$key] = kop_rinbox_count_for_me($key, $n);
        return array('counts' => $out);
    });
}

/** The "All tools" tab: every KOP tool, from the KOP Tools registry (kop_rinbox_all_tools(), inc/review-inbox-menu.php). */
function kop_rinbox_rest_all_tools(WP_REST_Request $req) {
    return kop_rinbox_rest(function () {
        return array('tools' => function_exists('kop_rinbox_all_tools') ? kop_rinbox_all_tools() : array());
    });
}

function kop_rinbox_rest_items(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $src = kop_rinbox_source($source);
        $views = array_keys($src['views']);
        $view = (string) $req->get_param('view');
        if (!in_array($view, $views, true)) $view = $views[0];
        $q = array(
            'view'   => $view,
            'search' => trim((string) $req->get_param('search')),
            'offset' => max(0, (int) $req->get_param('offset')),
            'limit'  => max(1, min(100, (int) ($req->get_param('limit') ?: 25))),
            'origin' => sanitize_key((string) $req->get_param('origin')),
            'filters' => kop_rinbox_chosen_filters($src, function ($name) use ($req) { return $req->get_param('f_' . $name); }),
        );
        $keys = $req->get_param('keys');
        if ($keys !== null && $keys !== '') {
            // Specific items (the page's own cards ask for theirs by id).
            $items = array();
            foreach (array_slice(array_filter(array_map('strval', is_array($keys) ? $keys : explode(',', (string) $keys)), 'strlen'), 0, 100) as $k) {
                $it = !empty($src['get']) ? call_user_func($src['get'], $k) : null;
                if ($it) $items[] = $it;
            }
            return array('items' => kop_rinbox_finish_items($source, $items), 'total' => count($items), 'view' => $view);
        }
        // The waiting view leaves out what is snoozed or handed to someone else,
        // and what is already on file (listed under its own view).
        if ($view === 'on_file' && !empty($src['on_file'])) {
            $res = kop_rinbox_on_file_list($src, $source, $q);
        } elseif ($view === $views[0]) {
            $res = kop_rinbox_list_unheld($src, $source, $q);
        } else {
            $res = call_user_func($src['list'], $q);
            $res['next_offset'] = $q['offset'] + count((array) ($res['items'] ?? array()));
        }
        $out = array('items' => kop_rinbox_finish_items($source, (array) ($res['items'] ?? array())), 'total' => (int) ($res['total'] ?? 0), 'view' => $view,
            'next_offset' => (int) $res['next_offset'], 'held' => (int) ($res['held'] ?? 0));
        if (!empty($src['view_counts'])) {
            try {
                $out['view_counts'] = array_map('intval', (array) call_user_func($src['view_counts'], $q));
            } catch (Throwable $e) {
                // The tabs just show no counts.
            }
        }
        if (!empty($src['on_file'])) {
            $n = count(kop_rinbox_on_file_keys($source));
            $out['view_counts']['on_file'] = $n;
            if (empty($src['on_file_in_list']) && isset($out['view_counts'][$views[0]])) $out['view_counts'][$views[0]] = max(0, $out['view_counts'][$views[0]] - $n - count(kop_rinbox_conflict_keys($source)));
        }
        return $out;
    });
}

/** The source's 'filters' values from a request: [name => value], only values that are one of its options. */
function kop_rinbox_chosen_filters(array $src, callable $param) {
    $out = array();
    foreach ((array) ($src['filters'] ?? array()) as $f) {
        $name = (string) ($f['name'] ?? '');
        $v = $name !== '' ? (string) call_user_func($param, $name) : '';
        if ($v !== '' && array_key_exists($v, (array) ($f['options'] ?? array()))) $out[$name] = $v;
    }
    return $out;
}

/** Suggestions for a field with 'lookup': [{value, label}], at most 20. */
function kop_rinbox_rest_lookup(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $src = kop_rinbox_source((string) $req->get_param('source'));
        $q = trim((string) $req->get_param('q'));
        if (empty($src['lookup']) || mb_strlen($q) < 2) return array('options' => array());
        $out = array();
        foreach ((array) call_user_func($src['lookup'], sanitize_key((string) $req->get_param('name')), $q) as $o) {
            if (isset($o['value']) && (string) $o['value'] !== '') {
                $out[] = array('value' => (string) $o['value'], 'label' => (string) ($o['label'] ?? $o['value']));
            }
        }
        return array('options' => array_slice($out, 0, 20));
    });
}

/** "Came from" choices with their counts: [{key, label, count}] (empty when the source has none). */
function kop_rinbox_rest_origins(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $src = kop_rinbox_source((string) $req->get_param('source'));
        if (empty($src['origins'])) return array('origins' => array());
        $views = array_keys($src['views']);
        $view = (string) $req->get_param('view');
        return array('origins' => array_values((array) call_user_func($src['origins'], array(
            'view' => in_array($view, $views, true) ? $view : $views[0],
            'status' => sanitize_key((string) $req->get_param('status')),
        ))));
    });
}

function kop_rinbox_rest_item(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        return array('item' => kop_rinbox_get_item((string) $req->get_param('source'), (string) $req->get_param('key')));
    });
}

/** The item again after a change, or null when it left the source. */
function kop_rinbox_after($source, $key) {
    try {
        return kop_rinbox_get_item($source, $key);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The card add-ons' actions ("Home of a program", "File under a young adult
 * program", ...) and their Undo; null when $action is the queue's own.
 */
function kop_rinbox_cross_act($source, $key, $action, array $params) {
    foreach (kop_rinbox_addons() as $a) {
        if (!is_callable($a['act']) || !in_array($action, (array) $a['actions'], true)) continue;
        $res = call_user_func($a['act'], $source, $key, $action, $params);
        if ($res !== null) return $res;
    }
    return null;
}

/**
 * Run one action on one item as the card's button does, logged in Recently
 * done with its Undo. Also used by volunteer projects' "Apply ticked"
 * (inc/volunteer-projects.php).
 */
function kop_rinbox_apply_action($source, $key, $action, array $params) {
    $src = kop_rinbox_source($source);
    $before = kop_rinbox_after($source, $key);
    $res = kop_rinbox_cross_act($source, $key, $action, $params);
    if ($res === null) $res = call_user_func($src['act'], $key, $action, $params);
    kop_rinbox_flush_counts();
    $res = is_array($res) ? $res : array();
    $res += array('message' => 'Done.', 'item' => kop_rinbox_after($source, $res['key'] ?? $key));
    kop_rinbox_log_action($source, (string) ($res['key'] ?? $key), $action, $params, $before, $res['item'], $res['message'], $res['undo'] ?? null);
    unset($res['undo']);
    return $res;
}

function kop_rinbox_rest_act(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $params = $req->get_param('params');
        return kop_rinbox_apply_action((string) $req->get_param('source'), (string) $req->get_param('key'),
            sanitize_key((string) $req->get_param('action')), is_array($params) ? wp_unslash($params) : array());
    });
}

function kop_rinbox_rest_tool(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $src = kop_rinbox_source((string) $req->get_param('source'));
        if (empty($src['tool'])) throw new RuntimeException('This queue has no tools.');
        $params = $req->get_param('params');
        $res = call_user_func($src['tool'], sanitize_key((string) $req->get_param('tool')), is_array($params) ? wp_unslash($params) : array());
        kop_rinbox_flush_counts();
        return (is_array($res) ? $res : array()) + array('message' => 'Done.');
    });
}

function kop_rinbox_rest_save(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $key = (string) $req->get_param('key');
        $src = kop_rinbox_source($source);
        if (empty($src['save'])) throw new RuntimeException('This queue has no editable fields.');
        $fields = $req->get_param('fields');
        $res = call_user_func($src['save'], $key, is_array($fields) ? wp_unslash($fields) : array());
        $res = is_array($res) ? $res : array();
        return $res + array('message' => 'Saved.', 'item' => kop_rinbox_after($source, $key));
    });
}

function kop_rinbox_rest_tags(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $tags = kop_rinbox_set_tags($source, (string) $req->get_param('key'), wp_unslash((array) $req->get_param('tags')));
        return array('tags' => $tags, 'message' => $tags ? 'Tags saved.' : 'Tags cleared.');
    });
}

function kop_rinbox_rest_ai(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $key = (string) $req->get_param('key');
        $src = kop_rinbox_source($source);
        $text = mb_substr(trim((string) $req->get_param('text')), 0, 60000);
        $res = !empty($src['ai_fill']) ? call_user_func($src['ai_fill'], $key, $text) : kop_rinbox_ai_fill_generic($source, $key, $text);
        $res = is_array($res) ? $res : array();
        return $res + array('message' => 'Done.', 'item' => kop_rinbox_after($source, $key));
    });
}

/* ---- Page assets ---------------------------------------------------------- */

add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('page-admin-submissions.php') && !is_page_template('templates/page-admin-submissions.php')) return;
    if (!current_user_can('manage_options')) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('kop-review-inbox', $uri . '/css/review-inbox.css', array('kop-admin-submissions'), filemtime($dir . '/css/review-inbox.css'));
    wp_enqueue_script('kop-review-inbox', $uri . '/js/review-inbox.js', array('kop-admin-submissions'), filemtime($dir . '/js/review-inbox.js'), true);
    wp_localize_script('kop-review-inbox', 'kopReviewInbox', array(
        'rest'  => esc_url_raw(rest_url('kop/v1/review-inbox/')),
        'nonce' => wp_create_nonce('wp_rest'),
    ));
}, 20);
