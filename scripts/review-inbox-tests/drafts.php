<?php
/**
 * scripts/test-review-inbox.php checks for the 'drafts' source
 * (inc/review-inbox/drafts.php): the site's own page and post drafts. A test
 * draft page is added to the scratch copy's wpdl_posts, edited last so it is
 * the first waiting card; the WordPress post functions are the stand-ins in
 * websites.php, with wp_update_post() here so it skips WordPress's own flags.
 */

if (!function_exists('wp_update_post')) {
    function wp_update_post($post, $wp_error = false) {
        $id = (int) $post['ID'];
        unset($post['ID'], $post['edit_date']);
        foreach ($post as $col => $v) {
            $GLOBALS['pdo']->prepare("UPDATE wpdl_posts SET `$col` = ? WHERE ID = ?")->execute(array($v, $id));
        }
        return $id;
    }
}
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

(function () {
    $pdo = $GLOBALS['pdo'];
    if (!$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'wpdl_posts'")->fetchColumn()) return;
    $pdo->prepare("INSERT INTO wpdl_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name,
                   post_modified, post_modified_gmt, post_parent, post_type, comment_status, ping_status, post_password, to_ping, pinged,
                   post_content_filtered, guid, menu_order, post_mime_type, comment_count)
                   VALUES (1, '2026-01-05 10:00:00', '0000-00-00 00:00:00', ?, 'Inbox Test Draft Page', '', 'draft', 'inbox-test-draft',
                   '2099-01-01 00:00:00', '2099-01-01 00:00:00', 0, 'page', 'closed', 'closed', '', '', '', '', '', 0, '', 0)")
        ->execute(array("<!-- wp:paragraph --><p>Draft words about [kop_icon name=\"x\"] the program.</p><!-- /wp:paragraph -->"));
})();

function kop_rinbox_test_drafts(array $src, array $item, callable $check) {
    $id = (int) $item['key'];
    $row = function () use ($id) {
        return $GLOBALS['pdo']->query('SELECT post_status, post_date, post_date_gmt, post_title, post_excerpt FROM wpdl_posts WHERE ID = ' . $id)->fetch(PDO::FETCH_ASSOC);
    };
    $check('drafts: the first waiting card is the test draft, text without block markup or shortcodes', $item['title'] === 'Inbox Test Draft Page'
        && $item['text'] === 'Draft words about the program.' && strpos($item['subtitle'], 'Page') === 0 && !empty($item['preview']['url']), json_encode($item['text']));
    $check('drafts: a draft offers publish, schedule, trash', kop_rinbox_test_own_actions($item) === array('publish', 'schedule', 'trash'),
        implode(',', kop_rinbox_test_own_actions($item)));
    $pages = call_user_func($src['list'], array('view' => 'draft', 'search' => 'Inbox Test Draft', 'offset' => 0, 'limit' => 5, 'filters' => array('type' => 'post')));
    $check('drafts: the type filter leaves a page out of Posts', $pages['total'] === 0);
    $counts = call_user_func($src['view_counts'], array());
    $check('drafts: every tab has a count', array_keys($counts) === array_keys($src['views']) && $counts['draft'] >= 1, json_encode($counts));

    call_user_func($src['save'], $item['key'], array('title' => 'Inbox Test Draft Page, edited', 'excerpt' => 'A short summary.'));
    $r = $row();
    $check('drafts: save writes the title and excerpt', $r['post_title'] === 'Inbox Test Draft Page, edited' && $r['post_excerpt'] === 'A short summary.');
    try {
        call_user_func($src['save'], $item['key'], array('title' => ' '));
        $check('drafts: an empty title is refused', false);
    } catch (RuntimeException $e) {
        $check('drafts: an empty title is refused', true, $e->getMessage());
    }

    // Publish, then Back to draft puts the old status and dates back.
    $res = call_user_func($src['act'], $item['key'], 'publish', array());
    $r = $row();
    $check('drafts: publish puts it live, dated now', $r['post_status'] === 'publish' && $r['post_date'] !== '2026-01-05 10:00:00', $res['message']);
    $live = kop_rinbox_get_item('drafts', $item['key']);
    $check('drafts: a published one offers Back to draft (the Undo)', kop_rinbox_test_own_actions($live) === array('unpublish'));
    $res = call_user_func($src['act'], $item['key'], 'unpublish', array());
    $r = $row();
    $check('drafts: Back to draft restores the draft and its dates', $r['post_status'] === 'draft' && $r['post_date'] === '2026-01-05 10:00:00'
        && $r['post_date_gmt'] === '0000-00-00 00:00:00' && get_post_meta($id, '_kop_rinbox_was', true) === '', $res['message']);

    // Schedule: a bad date and a past one are refused; a future one schedules, Back to draft cancels.
    foreach (array('next tuesday', '2001-01-01 09:00') as $bad) {
        try {
            call_user_func($src['act'], $item['key'], 'schedule', array('when' => $bad));
            $check("drafts: schedule refuses \"$bad\"", false);
        } catch (RuntimeException $e) {
            $check("drafts: schedule refuses \"$bad\"", $row()['post_status'] === 'draft', $e->getMessage());
        }
    }
    $res = call_user_func($src['act'], $item['key'], 'schedule', array('when' => '2098-03-04 08:30'));
    $r = $row();
    $check('drafts: schedule sets the date', $r['post_status'] === 'future' && $r['post_date'] === '2098-03-04 08:30:00', $res['message']);
    $future = kop_rinbox_get_item('drafts', $item['key']);
    $check('drafts: a scheduled one offers Publish now and Back to draft', kop_rinbox_test_own_actions($future) === array('publish', 'unpublish'));
    call_user_func($src['act'], $item['key'], 'unpublish', array());
    $check('drafts: Back to draft cancels the schedule', $row()['post_status'] === 'draft' && $row()['post_date'] === '2026-01-05 10:00:00');

    // Trash and Restore.
    $res = call_user_func($src['act'], $item['key'], 'trash', array());
    $check('drafts: trash', $row()['post_status'] === 'trash', $res['message']);
    $trashed = kop_rinbox_get_item('drafts', $item['key']);
    $check('drafts: a trashed one offers Restore and no preview', kop_rinbox_test_own_actions($trashed) === array('untrash') && empty($trashed['preview']));
    call_user_func($src['act'], $item['key'], 'untrash', array());
    $check('drafts: Restore puts it back as a draft', $row()['post_status'] === 'draft');
    try {
        call_user_func($src['act'], $item['key'], 'unpublish', array());
        $check('drafts: Back to draft on a draft is refused', false);
    } catch (RuntimeException $e) {
        $check('drafts: Back to draft on a draft is refused', true, $e->getMessage());
    }
    $check('drafts: volunteers never see this queue', !function_exists('kop_vol_never_sources') || in_array('drafts', kop_vol_never_sources(), true));
}
