<?php
/**
 * scripts/test-review-inbox.php checks for the page's own five types
 * (inc/review-inbox/native.php): rename and recategorise, tags in the row's
 * own column, and "Move to" another queue with its Undo.
 */

// WordPress functions the queue inserts use that the harness does not have.
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return filter_var((string) $u, FILTER_SANITIZE_URL); } }
if (!function_exists('remove_filter')) { function remove_filter() { return true; } }
if (!function_exists('get_post_status')) { function get_post_status() { return false; } }
if (!function_exists('get_the_title')) { function get_the_title() { return ''; } }
if (!function_exists('get_edit_post_link')) { function get_edit_post_link() { return ''; } }
require_once dirname(__DIR__, 2) . '/inc/source-submissions.php';

// Nothing may be waiting in the mirror: make the newest rows of each kind pending (scratch copy only).
foreach (array('news_submissions' => array('status', 'submitted'), 'lawsuits' => array('publication_status', 'pending'),
    'legislation' => array('publication_status', 'pending'), 'wiki_submissions' => array('status', 'submitted'),
    'suggested_edits' => array('status', 'pending')) as $kop_t => $kop_s) {
    $GLOBALS['pdo']->exec("UPDATE $kop_t SET {$kop_s[0]} = '{$kop_s[1]}' WHERE id IN (SELECT id FROM $kop_t ORDER BY id DESC LIMIT 3)");
}
unset($kop_t, $kop_s);

function kop_rinbox_test_news(array $src, array $item, callable $check) {
    $pdo = kop_rinbox_pdo();
    $before = kop_rinbox_native_row('news', $item['key']);
    kop_rinbox_test_news_details($check);

    call_user_func($src['save'], $item['key'], array('article_title' => 'Retitled In Test', 'article_type' => 'closure'));
    $row = kop_rinbox_native_row('news', $item['key']);
    $check('news: rename and recategorise', $row['article_title'] === 'Retitled In Test' && $row['article_type'] === 'closure');
    try {
        call_user_func($src['save'], $item['key'], array('article_type' => 'gossip'));
        $check('news: a category that is not a choice is refused', false);
    } catch (RuntimeException $e) {
        $check('news: a category that is not a choice is refused', true);
    }

    $tags = kop_rinbox_set_tags('news', $item['key'], array('juvenile justice', 'Lawsuit'));
    $stored = json_decode((string) kop_rinbox_native_row('news', $item['key'])['tags'], true);
    $check('news: tags go in the article\'s own tags column, in the news vocabulary', is_array($stored) && in_array('Lawsuit', $stored, true), json_encode($stored));

    if (kop_rinbox_native_url('news', $row) !== '') {
        try {
            $res = call_user_func($src['act'], $item['key'], 'move', array('to' => 'lawsuit'));
            $log = kop_rinbox_native_moves()['news:' . (int) $item['key']] ?? null;
            $moved = kop_rinbox_native_row('news', $item['key']);
            $new = $log ? kop_rinbox_native_row('lawsuit', $log['done']['id']) : null;
            $check('news: move to Lawsuits makes a pending lawsuit and files the article as rejected',
                $new && $new['publication_status'] === 'pending' && $moved['status'] === 'rejected', $res['message'] ?? '');
            $item_now = kop_rinbox_native_item('news', $moved);
            $check('news: the moved article offers Undo', (bool) array_filter($item_now['actions'], function ($a) { return $a['id'] === 'unmove'; }));
            $check('news: a pending article offers every other destination', count(kop_rinbox_native_item('news', $before)['moves']) === count(kop_rdest_targets()) - 1);
            call_user_func($src['act'], $item['key'], 'unmove', array());
            $check('news: Undo takes the lawsuit back and the article is pending again',
                !kop_rinbox_native_row('lawsuit', $log['done']['id']) && kop_rinbox_native_row('news', $item['key'])['status'] === 'submitted');
        } catch (RuntimeException $e) {
            // A link already in the lawsuit records cannot move; that refusal is the right answer.
            $check('news: a move that would duplicate is refused with a reason', stripos($e->getMessage(), 'Already in') === 0, $e->getMessage());
        }
    }
    $pdo->prepare("UPDATE news_submissions SET status = 'submitted' WHERE id = ?")->execute(array((int) $item['key']));
    call_user_func($src['act'], $item['key'], 'move', array('to' => 'promo'));
    $check('news: Industry PR files the article itself as promotional', kop_rinbox_native_row('news', $item['key'])['status'] === 'promotional');
    call_user_func($src['act'], $item['key'], 'unmove', array());
    $check('news: Undo puts it back in the queue', kop_rinbox_native_row('news', $item['key'])['status'] === 'submitted');

    // Any record: a company picked for "Materials and links" takes the link, the article is filed as rejected
    // (with no facility note in its JSON); Undo takes the link off exactly and the article is pending again.
    $oid = (int) $pdo->query("SELECT id FROM wpdl_kop_operators WHERE json_data LIKE '%\"operator\"%' ORDER BY id LIMIT 1")->fetchColumn();
    $news_url = kop_rinbox_native_url('news', kop_rinbox_native_row('news', $item['key']));
    if ($oid && $news_url !== '') {
        kop_rinbox_test_with_wpdb_writes(function () use ($src, $item, $check, $oid, $news_url) {
            $urls = function () use ($oid) {
                return array_column(array_filter((array) (kop_gdl_company_load($oid)['operator']['resourceLinks'] ?? array()), 'is_array'), 'url');
            };
            $before = $urls();
            try {
                $res = call_user_func($src['act'], $item['key'], 'move', array('to' => 'resource', 'facility_id' => 'company:' . $oid, 'kind' => 'reference'));
            } catch (RuntimeException $e) {
                $check('news: a company already holding the link refuses it with a reason', stripos($e->getMessage(), 'already on') !== false, $e->getMessage());
                return;
            }
            $moved = kop_rinbox_native_row('news', $item['key']);
            $json = json_decode((string) ($moved['json_data'] ?? ''), true);
            $check('news: a company picked in "Move to" takes the link and the article is filed as rejected',
                in_array($news_url, $urls(), true) && $moved['status'] === 'rejected' && !isset($json['movedTo']), $res['message']);
            call_user_func($src['act'], $item['key'], 'unmove', array());
            $check('news: Undo takes the link off the company exactly and the article is pending again',
                $urls() === $before && kop_rinbox_native_row('news', $item['key'])['status'] === 'submitted');
        });
    }

    // "Connect a record": the article stays pending and is listed on the company (kop_operator_links) or noted on a
    // consultant; the card offers Take off, which undoes it exactly.
    if ($oid && $news_url !== '') {
        kop_rinbox_test_with_wpdb_writes(function () use ($src, $item, $check, $oid, $pdo) {
            $nid = (int) $item['key'];
            $linked = function () use ($pdo, $oid, $nid) {
                return (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_operator_links WHERE link_kind = 'news' AND operator_id = $oid AND link_id = $nid")->fetchColumn();
            };
            $was = $linked();
            $ids = array_map(function ($a) { return $a['id']; }, kop_rinbox_native_item('news', kop_rinbox_native_row('news', $nid))['actions']);
            $check('news: a pending article offers "Connect a record"', in_array('connect', $ids, true), json_encode($ids));
            try {
                $res = call_user_func($src['act'], $nid, 'connect', array('record' => 'company:' . $oid));
            } catch (RuntimeException $e) {
                $check('news: connecting a company already listing the article is refused with a reason', $was > 0, $e->getMessage());
                return;
            }
            $row = kop_rinbox_native_row('news', $nid);
            $check('news: connecting a company lists the article there and it stays pending',
                $linked() === $was + 1 && $row['status'] === 'submitted' && ($res['undo']['action'] ?? '') === 'unconnect:company:' . $oid, $res['message']);
            $ids = array_map(function ($a) { return $a['id']; }, kop_rinbox_native_item('news', $row)['actions']);
            $check('news: the card offers Take off for the company', in_array('unconnect:company:' . $oid, $ids, true), json_encode($ids));
            try {
                call_user_func($src['act'], $nid, 'connect', array('record' => 'company:' . $oid));
                $check('news: connecting the same company twice is refused', false);
            } catch (RuntimeException $e) {
                $check('news: connecting the same company twice is refused', true);
            }
            try {
                call_user_func($src['act'], $nid, 'connect', array('record' => '12'));
                $check('news: a program is refused (it goes under Facilities mentioned)', false);
            } catch (RuntimeException $e) {
                $check('news: a program is refused (it goes under Facilities mentioned)', stripos($e->getMessage(), 'Facilities mentioned') !== false);
            }
            call_user_func($src['act'], $nid, 'unconnect:company:' . $oid, array());
            $check('news: Take off unlists it from the company exactly', $linked() === $was && !kop_rinbox_native_connections($nid));

            $ct = '';
            foreach (array('wpdl_referrers_master', 'referrers_master') as $t) {
                if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn()) { $ct = $t; break; }
            }
            $cid = $ct !== '' ? (int) $pdo->query("SELECT id FROM `$ct` ORDER BY id LIMIT 1")->fetchColumn() : 0;
            try {
                if ($cid) kop_wbc_load_record(kop_wbc_pdo(), 'consultant', $cid);
            } catch (Throwable $e) {
                echo "  (a consultant's notes need MySQL: the notes path is not run here)\n";
                $cid = 0;
            }
            if ($cid) {
                $json = function () use ($pdo, $ct, $cid) { return (string) $pdo->query("SELECT json_data FROM `$ct` WHERE id = $cid")->fetchColumn(); };
                $before = $json();
                $res = call_user_func($src['act'], $nid, 'connect', array('record' => 'consultant:' . $cid));
                $check('news: connecting a consultant adds a note citing the article', $json() !== $before
                    && strpos($json(), str_replace('/', '\\/', kop_rinbox_native_url('news', kop_rinbox_native_row('news', $nid)))) !== false, $res['message']);
                call_user_func($src['act'], $nid, 'unconnect:consultant:' . $cid, array());
                $check('news: Take off removes the consultant\'s note exactly', $json() === $before);
            }
        });
    }

    // An article already on the site can still be filed under a school or listed on a young adult program.
    $pdo->prepare("UPDATE news_submissions SET status = 'approved' WHERE id = ?")->execute(array((int) $item['key']));
    $approved = kop_rinbox_native_row('news', $item['key']);
    if (kop_rinbox_native_url('news', $approved) !== '') {
        $ids = array_map(function ($m) { return $m['id']; }, kop_rinbox_native_item('news', $approved)['moves']);
        $check('news: an approved article offers Indian boarding schools and young adult programs only', $ids === array('indigenous', 'young_adult'), json_encode($ids));
        call_user_func($src['act'], $item['key'], 'move', array('to' => 'indigenous', 'school_id' => 0));
        $linked = (int) kop_ischools_pdo()->query('SELECT COUNT(*) FROM indigenous_school_news WHERE school_id = 0 AND news_id = ' . (int) $item['key'])->fetchColumn();
        $check('news: filing an approved article under the schools links it and keeps it approved',
            $linked === 1 && kop_rinbox_native_row('news', $item['key'])['status'] === 'approved');
        call_user_func($src['act'], $item['key'], 'unmove', array());
        $linked = (int) kop_ischools_pdo()->query('SELECT COUNT(*) FROM indigenous_school_news WHERE school_id = 0 AND news_id = ' . (int) $item['key'])->fetchColumn();
        $check('news: Undo unlinks it and it stays approved', $linked === 0 && kop_rinbox_native_row('news', $item['key'])['status'] === 'approved');
        $res = call_user_func($src['act'], $item['key'], 'move', array('to' => 'young_adult', 'ya_id' => 0, 'ya_name' => 'Test YA Program From News'));
        $prog = kop_ya_find_by_name(kop_ya_pdo(), 'Test YA Program From News');
        $check('news: an approved article listed on a new young adult program puts the link there and stays approved',
            $prog && strpos((string) $prog['links'], kop_rinbox_native_url('news', $approved)) !== false && kop_rinbox_native_row('news', $item['key'])['status'] === 'approved', $res['message'] ?? '');
        call_user_func($src['act'], $item['key'], 'unmove', array());
        $check('news: Undo takes the program it made back off', !kop_ya_find_by_name(kop_ya_pdo(), 'Test YA Program From News'));
    }
    $pdo->prepare('UPDATE news_submissions SET article_title = ?, article_type = ?, tags = ?, status = ? WHERE id = ?')
        ->execute(array($before['article_title'], $before['article_type'], $before['tags'], $before['status'], (int) $item['key']));
}

function kop_rinbox_test_legislation(array $src, array $item, callable $check) {
    $before = kop_rinbox_native_row('legislation', $item['key']);
    call_user_func($src['save'], $item['key'], array('position' => 'watch', 'sponsors' => array('Rep. A', ' ', 'Sen. B')));
    $row = kop_rinbox_native_row('legislation', $item['key']);
    $check('legislation: position and a sponsor list save', $row['position'] === 'watch' && json_decode($row['sponsors'], true) === array('Rep. A', 'Sen. B'), (string) $row['sponsors']);
    kop_rinbox_pdo()->prepare('UPDATE legislation SET position = ?, sponsors = ? WHERE id = ?')
        ->execute(array($before['position'], $before['sponsors'], (int) $item['key']));

    // The old Legislation page's list filters, as api/manage-submissions.php applies them.
    $pdo = kop_rinbox_pdo();
    $count = function (array $q, $sort = '') use ($pdo) {
        list($where, $params) = kop_rinbox_native_list_filters('legislation', $q);
        $st = $pdo->prepare('SELECT id FROM legislation ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' '
            . kop_rinbox_native_list_order('legislation', $sort));
        $st->execute($params);
        return count($st->fetchAll(PDO::FETCH_COLUMN));
    };
    $all = (int) $pdo->query('SELECT COUNT(*) FROM legislation')->fetchColumn();
    $fed = (int) $pdo->query("SELECT COUNT(*) FROM legislation WHERE jurisdiction = 'Federal'")->fetchColumn();
    $check('legislation: level filter splits federal from state bills', $count(array('level' => 'federal')) === $fed
        && $count(array('level' => 'state'), 'date') + $fed === $all, "federal $fed of $all");
    $place = (string) $pdo->query("SELECT jurisdiction FROM legislation WHERE jurisdiction <> '' LIMIT 1")->fetchColumn();
    if ($place !== '') {
        $n = $pdo->prepare('SELECT COUNT(*) FROM legislation WHERE jurisdiction = ?');
        $n->execute(array($place));
        $check('legislation: place filter', $count(array('jurisdiction' => $place)) === (int) $n->fetchColumn(), $place);
    }
    $check('legislation: "by date" sorts on the introduced date', strpos(kop_rinbox_native_list_order('legislation', 'date'), 'introduced_date DESC') !== false
        && kop_rinbox_native_list_order('data', 'date') === 'ORDER BY created_at DESC');
}

function kop_rinbox_test_lawsuit(array $src, array $item, callable $check) {
    $pdo = kop_rinbox_pdo();
    $check('lawsuit: typed dates read like the old save endpoint',
        kop_rinbox_native_date('March 3, 2021') === '2021-03-03' && kop_rinbox_native_date('2020-01-02') === '2020-01-02'
        && kop_rinbox_native_date('') === null && kop_rinbox_native_date('not a date') === null);
    list($where) = kop_rinbox_native_list_filters('lawsuit', array('level' => 'federal'));
    $check('lawsuit: no level filter (the old page had none)', $where === array());
    $check('lawsuit: "by date" sorts on the filing date', strpos(kop_rinbox_native_list_order('lawsuit', 'date'), 'filing_date DESC') !== false);

    // A pending case never files its documents (unreviewed uploads stay out of the library).
    $before = kop_rinbox_native_row('lawsuit', $item['key']);
    $pdo->prepare("UPDATE lawsuits SET publication_status = 'pending' WHERE id = ?")->execute(array((int) $item['key']));
    $res = kop_rinbox_native_lawsuit_file_docs($pdo, $item['key']);
    $check('lawsuit: a pending case files nothing', $res === array('folder' => 0, 'filed' => 0), json_encode($res));
    try {
        $pdo->prepare("UPDATE lawsuits SET publication_status = 'published', filebird_folder_id = NULL, document_urls = '[]' WHERE id = ?")->execute(array((int) $item['key']));
        $res = kop_rinbox_native_lawsuit_file_docs($pdo, $item['key']);
        $check('lawsuit: a published case runs the save endpoint\'s folder step', is_array($res) && $res['filed'] === 0, json_encode($res));
    } catch (Throwable $e) {
        $check('lawsuit: a published case runs the save endpoint\'s folder step', false, $e->getMessage());
    }
    // "Mark as lawsuit news": the card becomes a pending lawsuit article in the News queue; Undo brings it back.
    $url = 'https://example.org/kop-test-lawsuit-news-' . (int) $item['key'];
    $pdo->prepare("UPDATE lawsuits SET publication_status = 'pending', source_urls = ?, summary = ? WHERE id = ?")
        ->execute(array(wp_json_encode(array($url)), 'Test summary of the case.', (int) $item['key']));
    $pending = kop_rinbox_native_item('lawsuit', kop_rinbox_native_row('lawsuit', $item['key']));
    $check('lawsuit: a pending case with a link offers "Mark as lawsuit news"',
        (bool) array_filter($pending['actions'], function ($a) { return $a['id'] === 'lawsuit_news'; }));
    try {
        $res = call_user_func($src['act'], $item['key'], 'lawsuit_news', array());
        $log = kop_rinbox_native_moves()['lawsuit:' . (int) $item['key']] ?? null;
        $news = $log ? kop_rinbox_native_row('news', $log['done']['id']) : null;
        $check('lawsuit: "Mark as lawsuit news" makes a pending lawsuit article and files the case as rejected',
            $news && $news['status'] === 'submitted' && $news['article_type'] === 'lawsuit' && $news['summary'] === 'Test summary of the case.'
            && kop_rinbox_native_row('lawsuit', $item['key'])['publication_status'] === 'rejected', $res['message'] ?? '');
        $check('lawsuit: the marked case offers Undo and no second mark', (bool) array_filter(
            kop_rinbox_native_item('lawsuit', kop_rinbox_native_row('lawsuit', $item['key']))['actions'],
            function ($a) { return $a['id'] === 'unmove'; }));
        call_user_func($src['act'], $item['key'], 'unmove', array());
        $check('lawsuit: Undo takes the article back (deleted) and the case is pending again',
            $news && kop_rinbox_native_row('news', $news['id'])['status'] === 'deleted' && kop_rinbox_native_row('lawsuit', $item['key'])['publication_status'] === 'pending');
    } catch (Throwable $e) {
        $check('lawsuit: "Mark as lawsuit news" works', false, $e->getMessage());
    }

    $pdo->prepare('UPDATE lawsuits SET publication_status = ?, filebird_folder_id = ?, document_urls = ?, source_urls = ?, summary = ?, reviewer_notes = ? WHERE id = ?')
        ->execute(array($before['publication_status'], $before['filebird_folder_id'], $before['document_urls'], $before['source_urls'],
            $before['summary'], $before['reviewer_notes'], (int) $item['key']));
}

/** The News Processor's per-type details go into json_data next to what is there. */
function kop_rinbox_test_news_details(callable $check) {
    $json = kop_rinbox_native_news_details_merge(array('organizationLogoName' => 'Keep'), array(
        'closureFacilityName' => '  Example Ranch ', 'closureDate' => '2020-05-01', 'charges' => array('One', 'Two'),
        'needsAlternateTitle' => 'false', 'article_title' => 'not a detail'));
    $check('news: details merge into json_data, other keys kept', $json['organizationLogoName'] === 'Keep'
        && $json['closureFacilityName'] === 'Example Ranch' && $json['charges'] === "One\nTwo"
        && $json['needsAlternateTitle'] === false && !isset($json['article_title']), json_encode($json));
    $check('news: every News Processor detail key is editable', count(array_intersect(kop_rinbox_native_news_detail_keys(),
        array('plaintiffs', 'legalRep', 'pressReleases', 'relatedCoverage', 'staffMemberName', 'caseStatus', 'closureContext', 'ownership'))) === 8);
    $check('news: "by date" sorts on the publication date', strpos(kop_rinbox_native_list_order('news', 'date'), 'publication_date DESC') !== false);
}

/**
 * Every item sits under one Review inbox tab: people, the daily scans or imports.
 * SCIAD NET and Google Docs news once fell between the tabs (2026-10-25).
 * Called from the "recent" checks in scripts/test-review-inbox.php.
 */
function kop_rinbox_test_news_origins(callable $check) {
    $pdo = kop_rinbox_pdo();
    $rows = array(
        'people'  => array('Reader', ''),
        'scans'   => array('auto-discovery', 'auto-discovery via google-news-topic | score=5'),
        'imports' => array('admin (SCIAD NET import)', 'Found in SCIAD NET'),
        'gdocs'   => array('admin (Google Docs import)', 'Google Doc: Example'),
    );
    $ids = array();
    $ins = $pdo->prepare("INSERT INTO news_submissions (article_title, json_data, status, submitted_by, submission_notes) VALUES (?, '{}', 'draft', ?, ?)");
    foreach ($rows as $k => $r) {
        $ins->execute(array('Origin test ' . $k, $r[0], $r[1]));
        $ids[$k] = (int) $pdo->lastInsertId();
    }
    $in = implode(',', $ids);
    $under = function ($origin) use ($pdo, $in) {
        return array_map('intval', $pdo->query("SELECT id FROM news_submissions WHERE id IN ($in) AND " . kop_rinbox_native_origin_where('news', $origin) . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    };
    $people = $under('people');
    $scans = $under('scans');
    $imports = $under('imports');
    $check('news origins: people, scans and imports each get their own', $people === array($ids['people']) && $scans === array($ids['scans'])
        && $imports === array($ids['imports'], $ids['gdocs']), json_encode(compact('ids', 'people', 'scans', 'imports')));
    $pdo->exec("DELETE FROM news_submissions WHERE id IN ($in)");
}
