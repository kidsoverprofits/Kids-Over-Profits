<?php
/**
 * scripts/test-review-inbox.php checks for the 'story-arc-suggestions' source
 * (inc/review-inbox/story-arc-suggestions.php over inc/story-arc-suggestions.php)
 * and the arc rule lines it rests on (api/news-story-arcs.php): "facility:",
 * "not:" and the once-only Hyde / Refuge terms. The suggestion table starts
 * empty in the scratch copy, so the checks run from the empty hook: a run as
 * of the mirror's newest article, then Add, Dismiss and Undo on its cards.
 */

require_once dirname(__DIR__, 2) . '/inc/story-arc-suggestions.php';

function kop_rinbox_test_story_arc_suggestions($src, $item, callable $check) {
    kop_rinbox_test_story_arc_suggestions_from_empty($src, $check);
}

function kop_rinbox_test_story_arc_suggestions_from_empty($src, callable $check) {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    $pdo = kop_arcsug_pdo();
    $t = 'story-arc-suggestions';

    // The rule lines.
    $r = kop_news_arc_rules("Hyde School\r\nfacility: 11021\nnot: newspaper awards\nnot: #10464\nsince: 2025-07-01\nsince: soon");
    $check("$t: rule lines are read apart", $r['terms'] === array('hyde school') && $r['facilities'] === array(11021)
        && $r['not'] === array('newspaper awards') && $r['not_ids'] === array(10464) && $r['since'] === '2025-07-01', json_encode($r));
    $arc = array('match_terms' => "facility: 7\nnot: oklahoma");
    $check("$t: a facility line matches a linked article", kop_news_arc_match($arc, 'anything', array(3, 7)) === 'facility: 7');
    $check("$t: a not phrase keeps it out", kop_news_arc_match($arc, 'teen challenge of oklahoma', array(7)) === null);
    $check("$t: a not id keeps that one out", kop_news_arc_match(array('match_terms' => "x\nnot: #5"), 'x', array(), 5) === null
        && kop_news_arc_match(array('match_terms' => "x\nnot: #5"), 'x', array(), 6) === 'x');
    $check("$t: rule lines alone are not terms", kop_news_arc_terms("since: 2026-01-01\nnot: x\nfacility: 3") === array()
        && kop_news_arc_can_match("facility: 3") && !kop_news_arc_can_match("not: x\nsince: 2026-01-01"));
    $with = kop_news_arc_add_not_id("a\nb", 12);
    $check("$t: detach writes its not line once, Undo takes it out", $with === "a\nb\nnot: #12" && kop_news_arc_add_not_id($with, 12) === $with
        && kop_news_arc_drop_not_id($with, 12) === "a\nb");

    // The once-only fix: Hyde and Refuge lose the articles that only shared a word.
    $member = function ($arcSlug, $newsId) use ($pdo) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM news_submissions n JOIN news_story_arcs a ON a.id = n.story_arc_id WHERE a.slug = ? AND n.id = ?');
        $st->execute(array($arcSlug, $newsId));
        return (int) $st->fetchColumn() > 0;
    };
    $hydeBefore = $member('fuller-v-hyde-school', 10464);
    $fixed = kop_arcsug_fix_terms_1116($pdo);
    if ($hydeBefore) {
        $check("$t: Hyde no longer has the newspaper awards piece", count($fixed) === 2 && !$member('fuller-v-hyde-school', 10464), json_encode($fixed));
        $check("$t: Hyde keeps the suit's coverage, not 1974", $member('fuller-v-hyde-school', 223) && !$member('fuller-v-hyde-school', 10497));
        $check("$t: Refuge no longer has the Oklahoma suit, keeps its own", !$member('refuge-girls-academy', 11089) && $member('refuge-girls-academy', 384));
        $hyde = (int) $pdo->query("SELECT id FROM news_story_arcs WHERE slug = 'fuller-v-hyde-school'")->fetchColumn();
        kop_news_arc_scan($pdo, $hyde);
        $check("$t: a scan does not bring the awards piece back", !$member('fuller-v-hyde-school', 10464));
        $check("$t: the fix runs once", kop_arcsug_fix_terms_1116($pdo) === array());
    } else {
        echo "  (the mirror's Hyde story no longer holds #10464: fix checks skipped)\n";
    }

    // A run as of the mirror's newest approved article.
    $today = substr((string) $pdo->query("SELECT MAX(publication_date) FROM news_submissions WHERE status IN ('approved','published')")->fetchColumn(), 0, 10);
    $c = kop_arcsug_run($pdo, $today);
    $check("$t: a run as of $today", is_array($c), json_encode($c));
    $rows = $pdo->query("SELECT * FROM news_story_arc_suggestions")->fetchAll(PDO::FETCH_ASSOC);
    $new = array_values(array_filter($rows, function ($x) { return $x['kind'] === 'new'; }));
    $check("$t: it suggests new stories", count($new) > 0, count($new) . ' new: ' . implode(' | ', array_column($new, 'title')));
    $followed = array();
    foreach ($pdo->query("SELECT match_terms FROM news_story_arcs WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $mt) {
        $followed = array_merge($followed, kop_news_arc_rules($mt)['facilities']);
    }
    $refuge = array_filter($new, function ($x) { return strpos($x['facility_ids'], ',13358,') !== false; });
    $provo = array_filter($new, function ($x) { return strpos($x['facility_ids'], ',10371,') !== false; });
    $check("$t: a facility an active story follows is not suggested (Refuge by its articles, Provo Canyon by its label)", !$refuge && !$provo,
        json_encode(array_column($refuge + $provo, 'title')));
    $seen = array();
    $twice = array();
    foreach ($new as $x) {
        foreach (kop_arcsug_ids_of($x['facility_ids']) as $f) {
            if (isset($seen[$f])) $twice[] = $f;
            $seen[$f] = true;
        }
        $terms = kop_news_arc_rules($x['match_terms']);
        if (!$terms['facilities'] || $terms['terms'] || $terms['since'] === '') $twice[] = 'terms of ' . $x['id'];
    }
    $check("$t: each facility in one suggestion, terms are facility lines and a since line", !$twice, json_encode($twice));
    $c2 = kop_arcsug_run($pdo, $today);
    $check("$t: a second run adds nothing", $c2['new'] === 0 && $c2['retire'] === 0 && $c2['join'] === 0, json_encode($c2));

    // A card, then Add: a story on the news page with the cluster's articles; Undo takes it all back.
    $list = call_user_func($src['list'], array('view' => 'pending', 'search' => '', 'offset' => 0, 'limit' => 50, 'filters' => array('kind' => 'new')));
    $card = $list['items'][0] ?? null;
    $check("$t: new stories are listed", $card && strpos($card['title'], 'New story: ') === 0 && count($card['fields']) === 3
        && $card['actions'][0]['id'] === 'add' && !empty($card['actions'][0]['with_fields']), $card ? $card['title'] . ' / ' . $card['subtitle'] : 'none');
    if ($card) {
        $row = kop_arcsug_get($pdo, (int) $card['key']);
        $behind = (array) json_decode($row['article_ids'], true);
        $res = call_user_func($src['act'], $card['key'], 'add', array('fields' => array('title' => 'Test Story', 'description' => 'Edited.', 'match_terms' => $row['match_terms'])));
        $row = kop_arcsug_get($pdo, (int) $card['key']);
        $arc = $pdo->query('SELECT * FROM news_story_arcs WHERE id = ' . (int) $row['arc_id'])->fetch(PDO::FETCH_ASSOC);
        $ids = $pdo->query('SELECT id FROM news_submissions WHERE story_arc_id = ' . (int) $row['arc_id'])->fetchAll(PDO::FETCH_COLUMN);
        $notIn = array_diff(array_map('intval', $behind), array_map('intval', $ids));
        $check("$t: Add makes the story with the card's title and its articles", $arc && $arc['title'] === 'Test Story' && $arc['status'] === 'active'
            && $arc['slug'] === 'test-story' && $arc['description'] === 'Edited.' && $arc['facility_label'] !== '' && $row['status'] === 'added',
            $res['message'] . ' / ' . count($ids) . ' attached; not attached (already in a story): ' . implode(',', $notIn));
        $after = call_user_func($src['get'], $card['key']);
        $check("$t: an added card offers Undo and links the story", $after['actions'][0]['id'] === 'undo'
            && strpos(json_encode($after['links']), '?story=test-story') !== false);
        $c3 = kop_arcsug_run($pdo, $today);
        $check("$t: once added, it is followed and not suggested again", $c3['new'] === 0 && kop_arcsug_get($pdo, (int) $card['key'])['status'] === 'added', json_encode($c3));
        $res = call_user_func($src['act'], $card['key'], 'undo', array());
        $left = (int) $pdo->query('SELECT COUNT(*) FROM news_submissions WHERE story_arc_id = ' . (int) $arc['id'])->fetchColumn();
        $gone = !$pdo->query('SELECT COUNT(*) FROM news_story_arcs WHERE id = ' . (int) $arc['id'])->fetchColumn();
        $check("$t: Undo deletes the story and frees its articles", $gone && $left === 0 && kop_arcsug_get($pdo, (int) $card['key'])['status'] === 'pending', $res['message']);

        call_user_func($src['act'], $card['key'], 'dismiss', array());
        kop_arcsug_run($pdo, $today);
        $check("$t: a dismissed story stays dismissed while nothing new comes in", kop_arcsug_get($pdo, (int) $card['key'])['status'] === 'dismissed');
        call_user_func($src['act'], $card['key'], 'undo', array());
        $check("$t: Back to review", kop_arcsug_get($pdo, (int) $card['key'])['status'] === 'pending');
        $saved = call_user_func($src['save'], $card['key'], array('title' => 'Kept Title'));
        $check("$t: the card's edits are kept", kop_arcsug_get($pdo, (int) $card['key'])['title'] === 'Kept Title', $saved['message']);
    }

    // A story gone quiet: archived on Add, active again on Undo.
    $pdo->exec("INSERT INTO news_story_arcs (title, slug, description, match_terms, facility_label, facility_url, status, display_order, created_at)
                VALUES ('Quiet Test Story', 'quiet-test-story', '', 'zzzz no such phrase', '', '', 'active', 0, '2020-01-01 00:00:00')");
    $quietId = (int) $pdo->lastInsertId();
    kop_arcsug_run($pdo, $today);
    $q = $pdo->query("SELECT * FROM news_story_arc_suggestions WHERE skey = 'retire:$quietId'")->fetch(PDO::FETCH_ASSOC);
    $qi = $q ? call_user_func($src['get'], (string) $q['id']) : null;
    $check("$t: a story with no article in 3 weeks is suggested for archiving, naming what waits to replace it", $q && $q['status'] === 'pending'
        && strpos(json_encode($qi['details']), 'Waiting to take its place') !== false, $qi ? json_encode($qi['details']) : '');
    if ($q) {
        call_user_func($src['act'], (string) $q['id'], 'add', array());
        $st = (string) $pdo->query("SELECT status FROM news_story_arcs WHERE id = $quietId")->fetchColumn();
        call_user_func($src['act'], (string) $q['id'], 'undo', array());
        $st2 = (string) $pdo->query("SELECT status FROM news_story_arcs WHERE id = $quietId")->fetchColumn();
        $check("$t: Add archives it, Undo makes it active", $st === 'archived' && $st2 === 'active', "$st / $st2");
    }
    // A story with an article from the last 3 weeks stays.
    $pdo->exec("INSERT INTO news_story_arcs (title, slug, description, match_terms, facility_label, facility_url, status, display_order, created_at)
                VALUES ('Fresh Test Story', 'fresh-test-story', '', 'zzzz no such phrase', '', '', 'active', 0, '2020-01-01 00:00:00')");
    $freshId = (int) $pdo->lastInsertId();
    $recent = (int) $pdo->query("SELECT id FROM news_submissions WHERE story_arc_id IS NULL AND status IN ('approved','published')
                                  AND publication_date >= '" . kop_arcsug_day_minus($today, 7) . "' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("UPDATE news_submissions SET story_arc_id = $freshId WHERE id = $recent");
    kop_arcsug_run($pdo, $today);
    $fr = $pdo->query("SELECT COUNT(*) FROM news_story_arc_suggestions WHERE skey = 'retire:$freshId'")->fetchColumn();
    $check("$t: a story with an article this week is not suggested for archiving", $recent > 0 && (int) $fr === 0, "#$recent");
    $pdo->exec("UPDATE news_submissions SET story_arc_id = NULL WHERE id = $recent");

    // Same-event coverage: Add puts the article in the story, Undo takes it out.
    $j = $pdo->query("SELECT * FROM news_story_arc_suggestions WHERE kind = 'join' AND status = 'pending' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($j) {
        call_user_func($src['act'], (string) $j['id'], 'add', array());
        $in = (int) $pdo->query('SELECT story_arc_id FROM news_submissions WHERE id = ' . (int) $j['news_id'])->fetchColumn();
        call_user_func($src['act'], (string) $j['id'], 'undo', array());
        $out = $pdo->query('SELECT story_arc_id FROM news_submissions WHERE id = ' . (int) $j['news_id'])->fetchColumn();
        $check("$t: an article to add joins the story, Undo takes it off", $in === (int) $j['arc_id'] && $out === null, "#{$j['news_id']}");
    } else {
        echo "  (no same-event article outside a story in the mirror)\n";
    }

    // The settings tool keeps only positive numbers.
    if (kop_rinbox_test_options_persist()) {
        call_user_func($src['tool'], 'settings', array('min_articles' => '4', 'window_days' => '-3'));
        $s = kop_arcsug_settings();
        $check("$t: settings keep a good number, ignore a bad one", $s['min_articles'] === 4 && $s['window_days'] === 60 && $s['retire_days'] === 21, json_encode($s));
        update_option('kop_arc_suggest_settings', array());
    }
}
