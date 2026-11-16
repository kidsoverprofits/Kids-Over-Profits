<?php
/**
 * Suggested Ongoing Stories (plan 4.7 #18; owner's Tell Claude note #5,
 * 2026-11-13: "a way to automatically update ongoing stories based on what's
 * currently trending").
 *
 * A daily pass over approved news proposes three kinds of change to the
 * Ongoing Stories (news_story_arcs, api/news-story-arcs.php). Nothing goes on
 * the site until it is added at Review inbox > Suggested ongoing stories, and
 * every add has Undo:
 *
 *  - new:    a facility several outlets keep writing about. Trending (the
 *            defaults; the owner changes them with the queue's Settings tool):
 *            at least 3 approved articles linked to the facility in the last
 *            60 days, from at least 2 outlets, spread over at least 14 days
 *            (so one event copied by a wire service is not a story), the
 *            newest within 30 days, and not already followed by an active
 *            story. Facilities whose articles are mostly the same ones (two
 *            records of one youth centre) make one suggestion. The story's
 *            terms are "facility: <id>" lines and a since line at the first
 *            article, never loose words, so it cannot pull in articles that
 *            only share a word (Hyde, Refuge, Provo Canyon, 2026-11-16).
 *  - retire: an active story with no approved article in 3 weeks (owner,
 *            2026-11-16: a story that has gone 3 weeks without an update makes
 *            way for a more current one; the card names the new stories
 *            waiting to take its place). Adding it
 *            archives the story (off the news page, articles kept).
 *  - join:   a recent approved article in no story, grouped as the same event
 *            ("Also covered by") with an article already in an active story.
 *
 * A dismissed new story comes back only when 3 (min_articles) more articles
 * arrive after it was dismissed; a dismissed retire comes back after another
 * 3 weeks; a dismissed join never comes back. A suggestion that stops
 * fitting (the story got a new article, the article joined a story) is
 * marked stale.
 *
 * Table (records DB, kop_seed_pdo()): news_story_arc_suggestions.
 * Queue: inc/review-inbox/story-arc-suggestions.php.
 * Test: scripts/test-review-inbox.php --source=story-arc-suggestions.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/api/news-story-arcs.php';

define('KOP_ARCSUG_DB_VERSION', '1');

function kop_arcsug_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) $pdo = $GLOBALS['pdo'];
    if ($pdo) $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function kop_arcsug_ensure(PDO $pdo) {
    static $done = array();
    $k = spl_object_hash($pdo);
    if (isset($done[$k])) return;
    $done[$k] = true;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if (!$sqlite && get_option('kop_arcsug_db') === KOP_ARCSUG_DB_VERSION) return;
    $pdo->exec($sqlite
        ? "CREATE TABLE IF NOT EXISTS news_story_arc_suggestions (id INTEGER PRIMARY KEY AUTOINCREMENT, skey TEXT NOT NULL UNIQUE,
            kind TEXT NOT NULL, arc_id INTEGER, news_id INTEGER, facility_ids TEXT, title TEXT, description TEXT, match_terms TEXT,
            article_ids TEXT, evidence TEXT, status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL, updated_at TEXT,
            decided_by TEXT, decided_at TEXT, undo TEXT)"
        : "CREATE TABLE IF NOT EXISTS news_story_arc_suggestions (
            id int(11) NOT NULL AUTO_INCREMENT,
            skey varchar(80) NOT NULL COMMENT 'new:<facility ids>, retire:<arc>, join:<arc>:<news>',
            kind varchar(10) NOT NULL COMMENT 'new | retire | join',
            arc_id int(11) DEFAULT NULL,
            news_id int(11) DEFAULT NULL,
            facility_ids varchar(255) DEFAULT NULL COMMENT ',12,34, (new)',
            title varchar(255) DEFAULT NULL,
            description text DEFAULT NULL,
            match_terms text DEFAULT NULL,
            article_ids text DEFAULT NULL COMMENT 'JSON list of the articles behind it',
            evidence text DEFAULT NULL COMMENT 'JSON: articles, outlets, first, last',
            status varchar(12) NOT NULL DEFAULT 'pending' COMMENT 'pending | added | dismissed | stale',
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            decided_by varchar(100) DEFAULT NULL,
            decided_at datetime DEFAULT NULL,
            undo text DEFAULT NULL COMMENT 'JSON: what Add changed',
            PRIMARY KEY (id),
            UNIQUE KEY skey (skey),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$sqlite) update_option('kop_arcsug_db', KOP_ARCSUG_DB_VERSION, false);
}

/* ---- Settings: what counts as trending -------------------------------- */

function kop_arcsug_defaults() {
    return array('window_days' => 60, 'min_articles' => 3, 'min_outlets' => 2, 'min_span_days' => 14,
                 'fresh_days' => 21, 'retire_days' => 21);
}

function kop_arcsug_settings() {
    $s = get_option('kop_arc_suggest_settings', array());
    $out = kop_arcsug_defaults();
    foreach ($out as $k => $v) {
        if (is_array($s) && isset($s[$k]) && (int) $s[$k] > 0) $out[$k] = (int) $s[$k];
    }
    return $out;
}

/** One line for the queue's help: the rule as it stands. */
function kop_arcsug_rule_line() {
    $s = kop_arcsug_settings();
    return sprintf('Trending now means at least %d approved articles about one facility in the last %d days, from at least %d outlets, '
        . 'spread over at least %d days, the newest within %d days. A story with no new article in %d days is suggested for archiving, to make way for a more current one.',
        $s['min_articles'], $s['window_days'], $s['min_outlets'], $s['min_span_days'], $s['fresh_days'], $s['retire_days']);
}

/* ---- Helpers ----------------------------------------------------------- */

function kop_arcsug_now() {
    return gmdate('Y-m-d H:i:s');
}

function kop_arcsug_day_minus($today, $days) {
    return gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC') - (int) $days * 86400);
}

/** "The Press Herald", "pressherald.com" and "Press Herald" are one outlet. */
function kop_arcsug_outlet_key($name) {
    $k = strtolower(trim((string) $name));
    $k = preg_replace('/^the\s+/', '', $k);
    $k = preg_replace('/\.(com|org|net|news)$/', '', $k);
    return preg_replace('/[^a-z0-9]+/', '', $k);
}

/** A draft title: the facility's name and what most headlines are about. */
function kop_arcsug_title(array $names, array $headlines) {
    $kinds = array(
        'Lawsuit'       => '/\b(lawsuits?|sues?|sued|suing|suit|plaintiffs?|complaint)\b/i',
        'Closure'       => '/\b(clos(e|es|ed|ing|ure)|shut(s|ting)? down|shutters?)\b/i',
        'Trial'         => '/\b(trials?|mistrial|jury|verdict)\b/i',
        'Criminal Case' => '/\b(arrest(ed|s)?|charged?|charges|indict(ed|ment)|sentenc(ed|ing))\b/i',
        'Escapes'       => '/\b(escape[sd]?|ran away|runaways?)\b/i',
        'Investigation' => '/\b(investigat(ion|ed|ing|es)|inspect(ion|ions|ors)|audit)\b/i',
        'Settlement'    => '/\b(settle(d|ment|ments)?|settlement fund)\b/i',
        'Licence'       => '/\b(licen[cs]e[sd]?|sanction(s|ed)?|revoked?)\b/i',
    );
    $best = '';
    $bestN = 0;
    foreach ($kinds as $label => $re) {
        $n = 0;
        foreach ($headlines as $h) $n += preg_match($re, (string) $h);
        if ($n > $bestN) {
            $best = $label;
            $bestN = $n;
        }
    }
    $names = array_values(array_unique(array_filter(array_map('trim', $names))));
    $who = count($names) > 1 ? $names[0] . ' and ' . $names[1] : ($names[0] ?? 'Facility');
    return $best !== '' ? $who . ' ' . $best : $who;
}

function kop_arcsug_get(PDO $pdo, $id) {
    kop_arcsug_ensure($pdo);
    $st = $pdo->prepare('SELECT * FROM news_story_arc_suggestions WHERE id = ?');
    $st->execute(array((int) $id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kop_arcsug_ids_of($csv) {
    return array_values(array_filter(array_map('intval', explode(',', (string) $csv))));
}

/** Active stories with their rules and approved members. */
function kop_arcsug_active_arcs(PDO $pdo) {
    $arcs = array();
    foreach ($pdo->query("SELECT id, title, slug, match_terms, facility_label, created_at FROM news_story_arcs WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $a['rules'] = kop_news_arc_rules($a['match_terms']);
        $a['members'] = array();
        $a['latest'] = '';
        $arcs[(int) $a['id']] = $a;
    }
    if ($arcs) {
        $rows = $pdo->query("SELECT id, story_arc_id, story_group_id, publication_date FROM news_submissions
                              WHERE story_arc_id IS NOT NULL AND status IN ('approved', 'published')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $aid = (int) $r['story_arc_id'];
            if (!isset($arcs[$aid])) continue;
            $arcs[$aid]['members'][(int) $r['id']] = $r;
            $d = substr((string) $r['publication_date'], 0, 10);
            if ($d > $arcs[$aid]['latest']) $arcs[$aid]['latest'] = $d;
        }
    }
    return $arcs;
}

/**
 * Facilities trending as of $today: [ids, names, articles [id => row], outlets, first, last].
 * Overlapping facilities (most articles shared) come out as one cluster.
 */
function kop_arcsug_trending(PDO $pdo, $today, array $s) {
    $from = kop_arcsug_day_minus($today, $s['window_days']);
    $st = $pdo->prepare("SELECT l.facility_id, f.name AS facility_name, s.id, s.publication_name, s.publication_date, s.story_arc_id,
                                s.story_group_id, s.article_title, s.alternate_title, s.article_url
                           FROM news_submissions s
                           JOIN news_facility_links l ON l.news_id = s.id
                           LEFT JOIN facilities_v2 f ON f.id = l.facility_id
                          WHERE s.status IN ('approved', 'published') AND s.publication_date >= ? AND s.publication_date <= ?
                          ORDER BY s.publication_date ASC, s.id ASC");
    $st->execute(array($from, $today . ' 23:59:59'));
    $by = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fid = (int) $r['facility_id'];
        $by[$fid]['name'] = (string) ($r['facility_name'] ?: ('Facility #' . $fid));
        $by[$fid]['articles'][(int) $r['id']] = $r;
    }
    $fresh = kop_arcsug_day_minus($today, $s['fresh_days']);
    $hot = array();
    foreach ($by as $fid => $f) {
        if (count($f['articles']) < $s['min_articles']) continue;
        $outlets = array();
        foreach ($f['articles'] as $a) $outlets[kop_arcsug_outlet_key($a['publication_name'])] = true;
        unset($outlets['']);
        $dates = array_map(function ($a) { return substr((string) $a['publication_date'], 0, 10); }, $f['articles']);
        $first = min($dates);
        $last = max($dates);
        $span = (strtotime($last) - strtotime($first)) / 86400;
        if (count($outlets) < $s['min_outlets'] || $span < $s['min_span_days'] || $last < $fresh) continue;
        $hot[$fid] = $f;
    }
    // Fold facilities that share most of their articles (one story, two records).
    $clusters = array();
    foreach ($hot as $fid => $f) {
        $placed = false;
        foreach ($clusters as &$c) {
            $shared = count(array_intersect_key($c['articles'], $f['articles']));
            if ($shared * 2 >= min(count($c['articles']), count($f['articles']))) {
                $c['ids'][] = $fid;
                $c['names'][] = $f['name'];
                $c['articles'] += $f['articles'];
                $placed = true;
                break;
            }
        }
        unset($c);
        if (!$placed) $clusters[] = array('ids' => array($fid), 'names' => array($f['name']), 'articles' => $f['articles']);
    }
    foreach ($clusters as &$c) {
        sort($c['ids']);
        uasort($c['articles'], function ($a, $b) { return strcmp((string) $a['publication_date'], (string) $b['publication_date']) ?: ((int) $a['id'] - (int) $b['id']); });
        $outlets = array();
        foreach ($c['articles'] as $a) $outlets[kop_arcsug_outlet_key($a['publication_name'])] = (string) $a['publication_name'];
        unset($outlets['']);
        $c['outlets'] = array_values($outlets);
        $first = reset($c['articles']);
        $last = end($c['articles']);
        $c['first'] = substr((string) $first['publication_date'], 0, 10);
        $c['last'] = substr((string) $last['publication_date'], 0, 10);
    }
    unset($c);
    return $clusters;
}

/**
 * Is the cluster followed already? An active story has a facility line for one of
 * its facilities, is labelled with one's name or matches on that name, or holds
 * half its articles.
 */
function kop_arcsug_covered(array $cluster, array $arcs) {
    $names = array_map(function ($n) { return mb_strtolower(trim((string) $n)); }, $cluster['names']);
    foreach ($arcs as $arc) {
        if (in_array(mb_strtolower(trim((string) ($arc['facility_label'] ?? ''))), $names, true)) return true;
        if (array_intersect($names, $arc['rules']['terms'])) return true;
    }
    $inArc = 0;
    foreach ($cluster['articles'] as $a) {
        if (!empty($a['story_arc_id']) && isset($arcs[(int) $a['story_arc_id']])) $inArc++;
    }
    if ($inArc * 2 >= count($cluster['articles'])) return true;
    foreach ($arcs as $arc) {
        if (array_intersect($cluster['ids'], $arc['rules']['facilities'])) return true;
    }
    return false;
}

/** Upsert by skey: a new row waits; an existing one is refreshed per $refresh(row): array of columns, or null to leave it. */
function kop_arcsug_put(PDO $pdo, $skey, array $cols, ?callable $refresh = null) {
    $st = $pdo->prepare('SELECT * FROM news_story_arc_suggestions WHERE skey = ?');
    $st->execute(array($skey));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $cols += array('skey' => $skey, 'status' => 'pending', 'created_at' => kop_arcsug_now());
        $pdo->prepare('INSERT INTO news_story_arc_suggestions (' . implode(', ', array_keys($cols)) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
        return 'new';
    }
    $set = $refresh ? $refresh($row) : null;
    if (!$set) return 'kept';
    $set['updated_at'] = kop_arcsug_now();
    $sql = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($set)));
    $pdo->prepare("UPDATE news_story_arc_suggestions SET $sql WHERE id = ?")->execute(array_merge(array_values($set), array((int) $row['id'])));
    return 'refreshed';
}

/* ---- The daily pass ---------------------------------------------------- */

/**
 * Work out what to suggest as of $today (YYYY-MM-DD, default today).
 * Returns counts: new, retire, join (new rows), refreshed, stale.
 */
function kop_arcsug_run(PDO $pdo, $today = null) {
    kop_arcsug_ensure($pdo);
    $today = $today ?: gmdate('Y-m-d');
    $s = kop_arcsug_settings();
    $arcs = kop_arcsug_active_arcs($pdo);
    $counts = array('new' => 0, 'retire' => 0, 'join' => 0, 'refreshed' => 0, 'stale' => 0);
    $live = array();   // ids of suggestions that still fit

    // New stories, matched to an earlier suggestion by any shared facility.
    $earlier = $pdo->query("SELECT * FROM news_story_arc_suggestions WHERE kind = 'new'")->fetchAll(PDO::FETCH_ASSOC);
    foreach (kop_arcsug_trending($pdo, $today, $s) as $c) {
        if (kop_arcsug_covered($c, $arcs)) continue;
        $row = null;
        foreach ($earlier as $e) {
            if (array_intersect($c['ids'], kop_arcsug_ids_of($e['facility_ids']))) {
                $row = $e;
                break;
            }
        }
        $articleIds = array_keys($c['articles']);
        $evidence = json_encode(array('articles' => count($articleIds), 'outlets' => $c['outlets'], 'first' => $c['first'], 'last' => $c['last'],
            'names' => $c['names'], 'as_of' => $today), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $terms = implode("\n", array_map(function ($id) { return 'facility: ' . $id; }, $c['ids'])) . "\nsince: " . $c['first'];
        $fids = ',' . implode(',', $c['ids']) . ',';
        if (!$row) {
            kop_arcsug_put($pdo, 'new:' . implode('-', $c['ids']), array(
                'kind' => 'new', 'facility_ids' => $fids,
                'title' => kop_arcsug_title($c['names'], array_map(function ($a) { return ($a['alternate_title'] ?: $a['article_title']); }, $c['articles'])),
                'description' => '', 'match_terms' => $terms, 'article_ids' => json_encode($articleIds), 'evidence' => $evidence,
            ));
            $counts['new']++;
            $live[] = (int) $pdo->lastInsertId();
            continue;
        }
        $set = array('article_ids' => json_encode($articleIds), 'evidence' => $evidence, 'facility_ids' => $fids);
        if ($row['status'] === 'pending' || $row['status'] === 'stale') {
            if ($row['status'] === 'stale') $set['status'] = 'pending';
        } elseif ($row['status'] === 'dismissed' || $row['status'] === 'added') {
            // Back only when enough new articles came after it was decided (an added story that has since been archived or undone).
            $since = substr((string) $row['decided_at'], 0, 10);
            $newer = count(array_filter($c['articles'], function ($a) use ($since) { return substr((string) $a['publication_date'], 0, 10) > $since; }));
            if ($newer < $s['min_articles']) continue;
            $set += array('status' => 'pending', 'decided_by' => null, 'decided_at' => null, 'undo' => null, 'match_terms' => $terms);
        }
        $pdo->prepare('UPDATE news_story_arc_suggestions SET ' . implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($set)))
            . ', updated_at = ? WHERE id = ?')->execute(array_merge(array_values($set), array(kop_arcsug_now(), (int) $row['id'])));
        $counts['refreshed']++;
        $live[] = (int) $row['id'];
    }

    // Stories gone quiet.
    $quiet = kop_arcsug_day_minus($today, $s['retire_days']);
    foreach ($arcs as $aid => $arc) {
        $latest = $arc['latest'] !== '' ? $arc['latest'] : substr((string) $arc['created_at'], 0, 10);
        if ($latest === '' || $latest >= $quiet) continue;
        $ev = json_encode(array('articles' => count($arc['members']), 'last' => $arc['latest'], 'as_of' => $today));
        $r = kop_arcsug_put($pdo, 'retire:' . $aid, array('kind' => 'retire', 'arc_id' => $aid, 'title' => $arc['title'], 'evidence' => $ev),
            function ($row) use ($ev, $quiet) {
                if ($row['status'] === 'pending') return array('evidence' => $ev);
                if ($row['status'] === 'stale') return array('evidence' => $ev, 'status' => 'pending');
                if ($row['status'] === 'dismissed' && substr((string) $row['decided_at'], 0, 10) < $quiet) {
                    return array('evidence' => $ev, 'status' => 'pending', 'decided_by' => null, 'decided_at' => null);
                }
                return null;
            });
        if ($r === 'new') $counts['retire']++;
        if ($r === 'refreshed') $counts['refreshed']++;
        $st = $pdo->prepare('SELECT id FROM news_story_arc_suggestions WHERE skey = ?');
        $st->execute(array('retire:' . $aid));
        $live[] = (int) $st->fetchColumn();
    }

    // Same-event coverage of a story's articles that is in no story.
    $groups = array();   // story_group_id => arc id
    foreach ($arcs as $aid => $arc) {
        foreach ($arc['members'] as $m) {
            if (!empty($m['story_group_id'])) $groups[(int) $m['story_group_id']] = $aid;
        }
    }
    if ($groups) {
        $from = kop_arcsug_day_minus($today, $s['window_days']);
        $ph = implode(',', array_fill(0, count($groups), '?'));
        $st = $pdo->prepare("SELECT id, story_group_id, article_title, alternate_title, summary, facilities_mentioned, publication_date
                               FROM news_submissions
                              WHERE story_arc_id IS NULL AND status IN ('approved', 'published') AND publication_date >= ?
                                AND story_group_id IN ($ph)");
        $st->execute(array_merge(array($from), array_map('intval', array_keys($groups))));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $aid = $groups[(int) $n['story_group_id']];
            $arc = $arcs[$aid];
            if (kop_news_arc_excluded($arc['rules'], kop_news_arc_haystack($n), (int) $n['id'])) continue;
            if (!kop_news_arc_in_window($arc, $n['publication_date'])) continue;
            $r = kop_arcsug_put($pdo, 'join:' . $aid . ':' . (int) $n['id'],
                array('kind' => 'join', 'arc_id' => $aid, 'news_id' => (int) $n['id'], 'title' => $arc['title']),
                function ($row) { return $row['status'] === 'stale' ? array('status' => 'pending') : null; });
            if ($r === 'new') $counts['join']++;
            $q = $pdo->prepare('SELECT id FROM news_story_arc_suggestions WHERE skey = ?');
            $q->execute(array('join:' . $aid . ':' . (int) $n['id']));
            $live[] = (int) $q->fetchColumn();
        }
    }

    // Waiting suggestions that no longer fit.
    $live = array_values(array_unique(array_filter($live)));
    $waiting = $pdo->query("SELECT id FROM news_story_arc_suggestions WHERE status = 'pending'")->fetchAll(PDO::FETCH_COLUMN);
    $gone = array_diff(array_map('intval', $waiting), $live);
    if ($gone) {
        $pdo->exec("UPDATE news_story_arc_suggestions SET status = 'stale', updated_at = '" . kop_arcsug_now() . "' WHERE id IN (" . implode(',', $gone) . ')');
        $counts['stale'] = count($gone);
    }
    update_option('kop_arc_suggest_last_run', array('at' => kop_arcsug_now(), 'as_of' => $today) + $counts, false);
    return $counts;
}

/* ---- Add / Dismiss / Undo ---------------------------------------------- */

function kop_arcsug_changed(PDO $pdo, array $newsIds) {
    if (function_exists('kop_record_changed')) kop_record_changed('news', $newsIds);
    if (function_exists('do_action') && function_exists('home_url')) {
        do_action('litespeed_purge_url', home_url('/tti-news-feed/'));
        do_action('litespeed_purge_url', home_url('/'));
    }
}

function kop_arcsug_unique_slug(PDO $pdo, $title) {
    $base = kop_news_arc_slug($title) ?: 'story';
    $slug = $base;
    $st = $pdo->prepare('SELECT COUNT(*) FROM news_story_arcs WHERE slug = ?');
    for ($i = 2; $i < 50; $i++) {
        $st->execute(array($slug));
        if (!(int) $st->fetchColumn()) return $slug;
        $slug = $base . '-' . $i;
    }
    return $base . '-' . time();
}

/** Add the suggestion; $fields (title, description, match_terms) as edited on the card. Returns the message. */
function kop_arcsug_add(PDO $pdo, $id, array $fields, $who) {
    $row = kop_arcsug_get($pdo, $id);
    if (!$row) throw new RuntimeException('That suggestion is gone.');
    if ($row['status'] !== 'pending' && $row['status'] !== 'stale') throw new RuntimeException('This suggestion was already decided.');
    $undo = array();
    $touched = array();
    if ($row['kind'] === 'new') {
        $title = trim((string) ($fields['title'] ?? $row['title']));
        $terms = trim((string) ($fields['match_terms'] ?? $row['match_terms']));
        $desc = trim((string) ($fields['description'] ?? $row['description']));
        if ($title === '') throw new RuntimeException('The story needs a title.');
        if (!kop_news_arc_can_match($terms)) throw new RuntimeException('The match terms need a facility line or a phrase, or no article can join.');
        $fids = kop_arcsug_ids_of($row['facility_ids']);
        $label = '';
        if ($fids) {
            $st = $pdo->prepare('SELECT name FROM facilities_v2 WHERE id = ?');
            $st->execute(array($fids[0]));
            $label = (string) $st->fetchColumn();
        }
        $slug = kop_arcsug_unique_slug($pdo, $title);
        $pdo->prepare("INSERT INTO news_story_arcs (title, slug, description, match_terms, facility_label, facility_url, status, display_order)
                       VALUES (?, ?, ?, ?, ?, '', 'active', 0)")->execute(array($title, $slug, $desc, $terms, $label));
        $arcId = (int) $pdo->lastInsertId();
        // The articles behind the suggestion, then every other one the terms match.
        $attach = $pdo->prepare('UPDATE news_submissions SET story_arc_id = ? WHERE id = ? AND story_arc_id IS NULL');
        foreach ((array) json_decode((string) $row['article_ids'], true) as $nid) {
            $attach->execute(array($arcId, (int) $nid));
            if ($attach->rowCount()) $touched[] = (int) $nid;
        }
        kop_news_arc_scan($pdo, $arcId, $touched);
        $undo = array('arc_id' => $arcId, 'attached' => array_values(array_unique($touched)));
        $pdo->prepare('UPDATE news_story_arc_suggestions SET title = ?, description = ?, match_terms = ?, arc_id = ? WHERE id = ?')
            ->execute(array($title, $desc, $terms, $arcId, (int) $id));
        $msg = sprintf('Added "%s" to Ongoing Stories with %d articles; it shows on the news page now. Undo is on the Added tab.', $title, count($undo['attached']));
    } elseif ($row['kind'] === 'retire') {
        $n = $pdo->prepare("UPDATE news_story_arcs SET status = 'archived' WHERE id = ? AND status = 'active'");
        $n->execute(array((int) $row['arc_id']));
        if (!$n->rowCount()) throw new RuntimeException('That story is not active any more.');
        $undo = array('arc_id' => (int) $row['arc_id']);
        $msg = 'Archived: the story is off the news page; its articles are kept. Undo puts it back.';
    } else {
        $n = $pdo->prepare('UPDATE news_submissions SET story_arc_id = ? WHERE id = ? AND story_arc_id IS NULL');
        $n->execute(array((int) $row['arc_id'], (int) $row['news_id']));
        if (!$n->rowCount()) throw new RuntimeException('That article is in a story already.');
        $touched[] = (int) $row['news_id'];
        $undo = array('arc_id' => (int) $row['arc_id'], 'news_id' => (int) $row['news_id']);
        $msg = 'Added the article to the story. Undo takes it off.';
    }
    $pdo->prepare("UPDATE news_story_arc_suggestions SET status = 'added', decided_by = ?, decided_at = ?, undo = ? WHERE id = ?")
        ->execute(array((string) $who, kop_arcsug_now(), json_encode($undo), (int) $id));
    kop_arcsug_changed($pdo, $touched);
    return $msg;
}

function kop_arcsug_dismiss(PDO $pdo, $id, $who) {
    $row = kop_arcsug_get($pdo, $id);
    if (!$row) throw new RuntimeException('That suggestion is gone.');
    if ($row['status'] === 'added') throw new RuntimeException('Undo it first.');
    $pdo->prepare("UPDATE news_story_arc_suggestions SET status = 'dismissed', decided_by = ?, decided_at = ? WHERE id = ?")
        ->execute(array((string) $who, kop_arcsug_now(), (int) $id));
    return 'Dismissed; nothing on the site changed.';
}

/** Undo an add (exactly what it changed), or put a dismissed one back to review. */
function kop_arcsug_undo(PDO $pdo, $id, $who) {
    $row = kop_arcsug_get($pdo, $id);
    if (!$row) throw new RuntimeException('That suggestion is gone.');
    $u = (array) json_decode((string) $row['undo'], true);
    $touched = array();
    $msg = 'Waiting for review again.';
    if ($row['status'] === 'added') {
        if ($row['kind'] === 'new' && !empty($u['arc_id'])) {
            $st = $pdo->prepare('SELECT id FROM news_submissions WHERE story_arc_id = ?');
            $st->execute(array((int) $u['arc_id']));
            $touched = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $pdo->prepare('UPDATE news_submissions SET story_arc_id = NULL WHERE story_arc_id = ?')->execute(array((int) $u['arc_id']));
            $pdo->prepare('DELETE FROM news_story_arcs WHERE id = ?')->execute(array((int) $u['arc_id']));
            $msg = 'Undone: the story is gone from the news page and its articles are in no story again. The suggestion waits for review.';
        } elseif ($row['kind'] === 'retire' && !empty($u['arc_id'])) {
            $pdo->prepare("UPDATE news_story_arcs SET status = 'active' WHERE id = ?")->execute(array((int) $u['arc_id']));
            $msg = 'Undone: the story is active on the news page again.';
        } elseif ($row['kind'] === 'join' && !empty($u['news_id'])) {
            $pdo->prepare('UPDATE news_submissions SET story_arc_id = NULL WHERE id = ? AND story_arc_id = ?')
                ->execute(array((int) $u['news_id'], (int) $u['arc_id']));
            $touched[] = (int) $u['news_id'];
            $msg = 'Undone: the article is off the story again.';
        }
    }
    $pdo->prepare("UPDATE news_story_arc_suggestions SET status = 'pending', decided_by = ?, decided_at = ?, undo = NULL"
        . ($row['kind'] === 'new' ? ', arc_id = NULL' : '') . ' WHERE id = ?')
        ->execute(array((string) $who, kop_arcsug_now(), (int) $id));
    kop_arcsug_changed($pdo, $touched);
    return $msg;
}

/** Keep the card's edits (new stories only). */
function kop_arcsug_save(PDO $pdo, $id, array $fields) {
    $row = kop_arcsug_get($pdo, $id);
    if (!$row || $row['kind'] !== 'new') throw new RuntimeException('Only a suggested new story can be edited.');
    if ($row['status'] !== 'pending' && $row['status'] !== 'stale') throw new RuntimeException('Only a waiting suggestion can be edited.');
    $set = array();
    foreach (array('title', 'description', 'match_terms') as $f) {
        if (array_key_exists($f, $fields)) $set[$f] = trim((string) $fields[$f]);
    }
    if (!$set) return;
    $pdo->prepare('UPDATE news_story_arc_suggestions SET ' . implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($set)))
        . ' WHERE id = ?')->execute(array_merge(array_values($set), array((int) $id)));
}

/* ---- Once: two stories that pulled in articles sharing only a word ------ */

/**
 * 2026-11-16 (plan 4.7 #18): Fuller v. Hyde School matched "fuller" and "hyde", so
 * it held Hyde School items back to 1974 and, as its latest development, the
 * Press Herald's newspaper awards piece (#10464). It now follows "hyde school" and
 * the case name from the suit's first coverage (July 2025), without #10464. Refuge
 * Girls Academy Lawsuit's latest was the Teen Challenge of Oklahoma suit (#11089),
 * whose summary names the Refuge; "not: teen challenge of oklahoma" keeps it out.
 * Only terms still as they were are changed (an edit made in the tool since is
 * kept); kop_news_arc_prune() takes off the members the new lines keep out.
 * Returns the ids of the stories changed.
 */
function kop_arcsug_fix_terms_1116(PDO $pdo) {
    $fixes = array(
        'fuller-v-hyde-school' => array('from' => "fuller\nhyde",
            'to' => "hyde school\nfuller v. hyde\nsince: 2025-07-01\nnot: #10464"),
        'refuge-girls-academy' => array('from' => 'refuge girls academy',
            'to' => "refuge girls academy\nnot: teen challenge of oklahoma"),
    );
    $ids = array();
    foreach ($fixes as $slug => $f) {
        $st = $pdo->prepare('SELECT id, match_terms FROM news_story_arcs WHERE slug = ?');
        $st->execute(array($slug));
        $arc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$arc) continue;
        $now = str_replace(array("\r\n", "\r"), "\n", trim((string) $arc['match_terms']));
        if (strtolower($now) !== $f['from']) continue;
        $pdo->prepare('UPDATE news_story_arcs SET match_terms = ? WHERE id = ?')->execute(array($f['to'], (int) $arc['id']));
        kop_news_arc_prune($pdo, (int) $arc['id']);
        $ids[] = (int) $arc['id'];
    }
    return $ids;
}

add_action('init', function () {
    if (get_option('kop_arc_terms_1116') === '1') return;
    try {
        $pdo = kop_arcsug_pdo();
        if (!$pdo) return;
        $ids = kop_arcsug_fix_terms_1116($pdo);
        update_option('kop_arc_terms_1116', '1', false);
        if ($ids) kop_arcsug_changed($pdo, array());
    } catch (Throwable $e) {
        error_log('kop_arc_terms_1116: ' . $e->getMessage());
    }
}, 30);

/* ---- Daily run --------------------------------------------------------- */

add_action('init', function () {
    if (!wp_next_scheduled('kop_arc_suggest_daily')) {
        wp_schedule_event(time() + 1800, 'daily', 'kop_arc_suggest_daily');
    }
});

add_action('kop_arc_suggest_daily', function () {
    try {
        $pdo = kop_arcsug_pdo();
        if ($pdo) kop_arcsug_run($pdo);
    } catch (Throwable $e) {
        error_log('kop story suggestions: ' . $e->getMessage());
    }
});
