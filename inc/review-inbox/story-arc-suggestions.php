<?php
/**
 * Review inbox source: Suggested ongoing stories (inc/story-arc-suggestions.php).
 * A new story from what several outlets keep writing about, an archive for a
 * story gone quiet, or an article to add to a story it is the same event as.
 * Add does it, with Undo; Dismiss changes nothing.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('story-arc-suggestions', function () {
    if (!function_exists('kop_arcsug_run') || !kop_arcsug_pdo()) return null;
    return array(
        'label'    => 'Suggested ongoing stories',
        'group'    => 'Found by the news scans',
        'help'     => 'Each day the approved news is read for stories several outlets keep covering, Ongoing Stories gone quiet, and same-event '
            . 'coverage of a story that is in no story. Nothing changes on the news page until you add it, and Undo takes it back. '
            . kop_arcsug_rule_line() . ' ' . kop_rinbox_arcsug_last_run(),
        'views'    => array('pending' => 'To review', 'added' => 'Added', 'dismissed' => 'Dismissed', 'stale' => 'No longer fits', 'all' => 'All'),
        'view_counts' => 'kop_rinbox_arcsug_view_counts',
        'filters'  => array(array('name' => 'kind', 'label' => 'Kind', 'options' => array(
            'new' => 'New stories', 'retire' => 'Stories gone quiet', 'join' => 'Articles to add'))),
        'tools'    => function () {
            $s = kop_arcsug_settings();
            return array(
                array('id' => 'run', 'label' => 'Look now', 'style' => 'neutral',
                    'help' => 'Reads the approved news now instead of waiting for the daily run.'),
                array('id' => 'settings', 'label' => 'What counts as trending', 'style' => 'neutral', 'fold' => true,
                    'help' => 'Changes the rule for the next run. Suggestions waiting now are checked again against it.',
                    'params' => array(
                        array('name' => 'min_articles', 'label' => 'At least this many approved articles', 'type' => 'number', 'value' => $s['min_articles']),
                        array('name' => 'window_days', 'label' => 'in the last this many days', 'type' => 'number', 'value' => $s['window_days']),
                        array('name' => 'min_outlets', 'label' => 'from at least this many outlets', 'type' => 'number', 'value' => $s['min_outlets']),
                        array('name' => 'min_span_days', 'label' => 'spread over at least this many days (first to newest)', 'type' => 'number', 'value' => $s['min_span_days']),
                        array('name' => 'fresh_days', 'label' => 'the newest within this many days', 'type' => 'number', 'value' => $s['fresh_days']),
                        array('name' => 'retire_days', 'label' => 'Suggest archiving a story with no new article in this many days', 'type' => 'number', 'value' => $s['retire_days']),
                    )),
            );
        },
        'tool'     => 'kop_rinbox_arcsug_tool',
        'tool_url' => function_exists('get_stylesheet_directory_uri') ? get_stylesheet_directory_uri() . '/api/manage-story-arcs.php' : '',
        'count'    => function () {
            $pdo = kop_arcsug_pdo();
            kop_arcsug_ensure($pdo);
            return (int) $pdo->query("SELECT COUNT(*) FROM news_story_arc_suggestions WHERE status = 'pending'")->fetchColumn();
        },
        'list'     => 'kop_rinbox_arcsug_list',
        'get'      => function ($key) {
            $row = kop_arcsug_get(kop_arcsug_pdo(), (int) $key);
            return $row ? kop_rinbox_arcsug_item($row) : null;
        },
        'act'      => 'kop_rinbox_arcsug_act',
        'save'     => function ($key, array $fields) {
            kop_arcsug_save(kop_arcsug_pdo(), (int) $key, $fields);
            return array('message' => 'Saved. Add uses these.');
        },
        'no_ai'    => true,
    );
});

function kop_rinbox_arcsug_last_run() {
    $l = get_option('kop_arc_suggest_last_run');
    if (!is_array($l)) return 'The first run is within a day.';
    return sprintf('Last run %s: %d new stories, %d gone quiet, %d articles to add, %d no longer fit.',
        substr((string) $l['at'], 0, 10), (int) $l['new'], (int) $l['retire'], (int) $l['join'], (int) $l['stale']);
}

function kop_rinbox_arcsug_view_counts(array $q = array()) {
    $pdo = kop_arcsug_pdo();
    kop_arcsug_ensure($pdo);
    $by = array();
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM news_story_arc_suggestions GROUP BY status') as $r) $by[$r['status']] = (int) $r['n'];
    return array('pending' => $by['pending'] ?? 0, 'added' => $by['added'] ?? 0, 'dismissed' => $by['dismissed'] ?? 0,
                 'stale' => $by['stale'] ?? 0, 'all' => array_sum($by));
}

function kop_rinbox_arcsug_tool($id, array $params) {
    $pdo = kop_arcsug_pdo();
    if ($id === 'settings') {
        $s = kop_arcsug_settings();
        foreach ($s as $k => $v) {
            if (isset($params[$k]) && (int) $params[$k] > 0) $s[$k] = min(3650, (int) $params[$k]);
        }
        update_option('kop_arc_suggest_settings', $s, false);
        $id = 'run';
    }
    if ($id !== 'run') throw new RuntimeException('Unknown tool.');
    $c = kop_arcsug_run($pdo);
    kop_rinbox_flush_counts();
    return array('message' => sprintf('%d new stories, %d gone quiet, %d articles to add; %d refreshed, %d no longer fit.',
        $c['new'], $c['retire'], $c['join'], $c['refreshed'], $c['stale']));
}

function kop_rinbox_arcsug_list(array $q) {
    $pdo = kop_arcsug_pdo();
    kop_arcsug_ensure($pdo);
    $views = array('pending', 'added', 'dismissed', 'stale');
    $where = in_array($q['view'], $views, true) ? 'status = ?' : '1=1';
    $args = in_array($q['view'], $views, true) ? array($q['view']) : array();
    if (!empty($q['filters']['kind'])) {
        $where .= ' AND kind = ?';
        $args[] = $q['filters']['kind'];
    }
    if (($q['search'] ?? '') !== '') {
        $where .= ' AND title LIKE ?';
        $args[] = '%' . $q['search'] . '%';
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM news_story_arc_suggestions WHERE $where");
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    // New stories first, then by how recent their newest article is.
    $st = $pdo->prepare("SELECT * FROM news_story_arc_suggestions WHERE $where
                          ORDER BY CASE kind WHEN 'retire' THEN 0 WHEN 'new' THEN 1 ELSE 2 END, COALESCE(updated_at, created_at) DESC, id DESC
                          LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    $st->execute($args);
    return array('items' => array_map('kop_rinbox_arcsug_item', $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total);
}

/** Up to $max articles as card lines: "2026-10-11 · WAFF" => headline. */
function kop_rinbox_arcsug_article_lines(PDO $pdo, array $ids, $max = 12) {
    $ids = array_slice(array_values(array_filter(array_map('intval', $ids))), -$max);
    if (!$ids) return array();
    $st = $pdo->prepare('SELECT id, publication_date, publication_name, article_title, alternate_title FROM news_submissions WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY publication_date DESC, id DESC');
    $st->execute($ids);
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $out[] = array('label' => trim(substr((string) $a['publication_date'], 0, 10) . ' · ' . $a['publication_name'], ' ·'),
            'value' => (string) ($a['alternate_title'] ?: $a['article_title']) . ' (#' . (int) $a['id'] . ')');
    }
    return $out;
}

function kop_rinbox_arcsug_item(array $r) {
    $pdo = kop_arcsug_pdo();
    $ev = (array) json_decode((string) $r['evidence'], true);
    $statusLabels = array('pending' => 'To review', 'added' => 'Added', 'dismissed' => 'Dismissed', 'stale' => 'No longer fits');
    $waiting = $r['status'] === 'pending' || $r['status'] === 'stale';
    $details = array();
    $fields = array();
    $links = array();
    $facility = null;
    $feed = function_exists('home_url') ? home_url('/tti-news-feed/') : '/tti-news-feed/';

    if ($r['kind'] === 'new') {
        $kindLabel = 'New story';
        $subtitle = sprintf('%d articles from %d outlets, %s to %s', (int) ($ev['articles'] ?? 0), count((array) ($ev['outlets'] ?? array())),
            (string) ($ev['first'] ?? ''), (string) ($ev['last'] ?? ''));
        $details[] = array('label' => 'Outlets', 'value' => implode(', ', (array) ($ev['outlets'] ?? array())));
        $details = array_merge($details, kop_rinbox_arcsug_article_lines($pdo, (array) json_decode((string) $r['article_ids'], true)));
        $fids = kop_arcsug_ids_of($r['facility_ids']);
        if ($fids) {
            $names = (array) ($ev['names'] ?? array());
            $url = function_exists('kop_facility_page_url_for_name') && !empty($names[0]) ? (string) kop_facility_page_url_for_name($names[0]) : '';
            $facility = array('id' => $fids[0], 'name' => (string) ($names[0] ?? ('#' . $fids[0])), 'url' => $url);
        }
        if ($waiting) {
            $fields = array(
                array('name' => 'title', 'label' => 'Story title (shown on the news page)', 'type' => 'text', 'value' => (string) $r['title']),
                array('name' => 'description', 'label' => 'Description (optional, shown under the title)', 'type' => 'textarea', 'value' => (string) $r['description']),
                array('name' => 'match_terms', 'label' => 'Match terms ("facility: N" joins every article linked to that record; "since:" bounds it; "not:" keeps out a phrase or #article)',
                    'type' => 'textarea', 'value' => (string) $r['match_terms']),
            );
        }
        $addHelp = 'Puts the story on the news page under Ongoing Stories with the title and terms as they read in the boxes, and adds these articles '
            . 'and every other one its terms match. Undo deletes the story and takes its articles off again.';
    } elseif ($r['kind'] === 'retire') {
        $kindLabel = 'Gone quiet';
        $subtitle = !empty($ev['last']) ? 'Newest article ' . $ev['last'] . ', ' . (int) ($ev['articles'] ?? 0) . ' in all' : 'No approved article in it';
        $details[] = array('label' => 'Story', 'value' => (string) $r['title']);
        $next = $pdo->query("SELECT title FROM news_story_arc_suggestions WHERE kind = 'new' AND status = 'pending' ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
        $details[] = array('label' => 'Waiting to take its place', 'value' => $next ? implode('; ', $next) : 'No new story is suggested right now.');
        $addHelp = 'Archives the story: it leaves Ongoing Stories on the news page and the home page; its articles stay where they are. Undo makes it active again.';
    } else {
        $kindLabel = 'Article to add';
        $subtitle = 'Same event as an article already in "' . $r['title'] . '"';
        $details = kop_rinbox_arcsug_article_lines($pdo, array((int) $r['news_id']));
        $st = $pdo->prepare('SELECT summary, article_url FROM news_submissions WHERE id = ?');
        $st->execute(array((int) $r['news_id']));
        $n = $st->fetch(PDO::FETCH_ASSOC) ?: array();
        if (!empty($n['summary'])) $details[] = array('label' => 'Summary', 'value' => (string) $n['summary']);
        if (!empty($n['article_url'])) $links[] = array('label' => 'The article', 'url' => (string) $n['article_url']);
        $addHelp = 'Adds the article to the story, so it is listed with the story on the news page. Undo takes it off.';
    }
    if (!empty($r['arc_id']) && ($r['kind'] !== 'new' || $r['status'] === 'added')) {
        $st = $pdo->prepare('SELECT slug FROM news_story_arcs WHERE id = ?');
        $st->execute(array((int) $r['arc_id']));
        $slug = (string) $st->fetchColumn();
        if ($slug !== '') $links[] = array('label' => 'The story on the news page', 'url' => $feed . '?story=' . rawurlencode($slug));
    }
    if (!$waiting && !empty($r['decided_by'])) {
        $details[] = array('label' => $statusLabels[$r['status']] ?? $r['status'], 'value' => substr((string) $r['decided_at'], 0, 10) . ' by ' . $r['decided_by']);
    }
    if ($r['status'] === 'stale') {
        $details[] = array('label' => 'No longer fits', 'value' => 'The last run did not find this any more (a story took it in, or it is not trending now). It can still be added.');
    }

    $actions = array();
    if ($waiting) {
        $actions[] = array('id' => 'add', 'label' => $r['kind'] === 'retire' ? 'Approve: archive the story' : 'Approve: add it',
            'style' => 'approve', 'with_fields' => $r['kind'] === 'new', 'help' => $addHelp);
        $actions[] = array('id' => 'dismiss', 'label' => 'Dismiss', 'style' => 'reject',
            'help' => $r['kind'] === 'new' ? 'Nothing changes. It is suggested again only if more articles about it come in.'
                : ($r['kind'] === 'retire' ? 'The story stays active; it is suggested again after another quiet stretch.' : 'Nothing changes; this article is not suggested again.'));
    } elseif ($r['status'] === 'added') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo', 'help' => 'Takes back exactly what Add did, and the suggestion waits for review again.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => 'Back to review', 'style' => 'undo', 'help' => 'Nothing on the site changes; the suggestion waits for review again.');
    }

    return array(
        'key'          => (string) (int) $r['id'],
        'title'        => $kindLabel . ': ' . ($r['kind'] === 'join' ? kop_rinbox_arcsug_headline($pdo, (int) $r['news_id']) : (string) $r['title']),
        'subtitle'     => $subtitle,
        'url'          => '',
        'text'         => '',
        'facility'     => $facility,
        'details'      => $details,
        'links'        => $links,
        'created'      => (string) $r['created_at'],
        'status'       => (string) $r['status'],
        'status_label' => $statusLabels[$r['status']] ?? (string) $r['status'],
        'fields'       => $fields,
        'actions'      => $actions,
    );
}

function kop_rinbox_arcsug_headline(PDO $pdo, $newsId) {
    $st = $pdo->prepare('SELECT alternate_title, article_title FROM news_submissions WHERE id = ?');
    $st->execute(array((int) $newsId));
    $n = $st->fetch(PDO::FETCH_ASSOC);
    return $n ? (string) ($n['alternate_title'] ?: $n['article_title']) : 'Article #' . (int) $newsId;
}

function kop_rinbox_arcsug_act($key, $action, array $params) {
    $pdo = kop_arcsug_pdo();
    $who = kop_rinbox_reviewer();
    switch ($action) {
        case 'add':
            $msg = kop_arcsug_add($pdo, (int) $key, (array) ($params['fields'] ?? array()), $who);
            break;
        case 'dismiss':
            $msg = kop_arcsug_dismiss($pdo, (int) $key, $who);
            break;
        case 'undo':
            $msg = kop_arcsug_undo($pdo, (int) $key, $who);
            break;
        default:
            throw new RuntimeException('Unknown action.');
    }
    kop_rinbox_flush_counts();
    return array('message' => $msg);
}
