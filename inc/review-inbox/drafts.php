<?php
/**
 * Review inbox source: the site's own page and post drafts (WordPress posts of
 * type 'page' and 'post' that are drafts, waiting for review, or scheduled).
 * Publish puts one on the site now, Schedule sets a date, Trash uses
 * WordPress's trash. Before publishing or scheduling, the status and dates the
 * draft had are kept in _kop_rinbox_was, so "Back to draft" (the Undo in
 * Recently done) puts it back exactly as it was. Published shows what went
 * live in the last 60 days, Trashed what can still be restored.
 *
 * Unpublished writing: volunteers never see this queue (kop_vol_never_sources()).
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_RINBOX_DRAFT_TYPES = array('post' => 'Post', 'page' => 'Page');
const KOP_RINBOX_DRAFTS_RECENT_DAYS = 60;

kop_rinbox_register('drafts', function () {
    return array(
        'label'    => 'Page and post drafts',
        'group'    => 'Site content',
        'help'     => 'The site\'s own pages and posts that are not published yet. Preview one, then Publish it now, Schedule it for a date, or Trash it; Back to draft undoes a publish.',
        'views'    => array('draft' => 'Drafts', 'pending' => 'Pending review', 'future' => 'Scheduled',
            'recent' => 'Published (last ' . KOP_RINBOX_DRAFTS_RECENT_DAYS . ' days)', 'trash' => 'Trashed'),
        'filters'  => array(array('name' => 'type', 'label' => 'Type', 'options' => array('page' => 'Pages', 'post' => 'Posts'))),
        'view_counts' => function (array $q = array()) {
            $out = array();
            foreach (array('draft', 'pending', 'future', 'recent', 'trash') as $view) {
                $out[$view] = kop_rinbox_drafts_count($view, (string) ($q['filters']['type'] ?? ''));
            }
            return $out;
        },
        'count'    => function () {
            return kop_rinbox_drafts_count('draft', '') + kop_rinbox_drafts_count('pending', '');
        },
        'tool_url' => admin_url('edit.php?post_status=draft&post_type=page'),
        'no_ai'    => true,
        'list'     => 'kop_rinbox_drafts_list',
        'get'      => function ($key) {
            $p = kop_rinbox_drafts_post($key);
            return $p ? kop_rinbox_drafts_item($p) : null;
        },
        'act'      => 'kop_rinbox_drafts_act',
        'save'     => 'kop_rinbox_drafts_save',
    );
});

const KOP_RINBOX_DRAFTS_COLS = 'ID, post_author, post_date, post_date_gmt, post_modified, post_content, post_title, post_excerpt, post_status, post_name, post_parent, post_type';

function kop_rinbox_drafts_post($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        'SELECT ' . KOP_RINBOX_DRAFTS_COLS . " FROM {$wpdb->posts} WHERE ID = %d AND post_type IN ('post', 'page')", (int) $id
    )) ?: null;
}

/** The WHERE for a view (draft, pending, future, recent, trash) and type ('' = both). */
function kop_rinbox_drafts_where($view, $type, $search = '') {
    global $wpdb;
    $types = isset(KOP_RINBOX_DRAFT_TYPES[$type]) ? array($type) : array_keys(KOP_RINBOX_DRAFT_TYPES);
    $where = "post_type IN ('" . implode("', '", $types) . "')";
    if ($view === 'recent') {
        $since = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - KOP_RINBOX_DRAFTS_RECENT_DAYS * DAY_IN_SECONDS);
        $where .= $wpdb->prepare(" AND post_status = 'publish' AND post_date >= %s", $since);
    } else {
        $where .= $wpdb->prepare(' AND post_status = %s', in_array($view, array('draft', 'pending', 'future', 'trash'), true) ? $view : 'draft');
    }
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= $wpdb->prepare(' AND (post_title LIKE %s OR post_name LIKE %s)', $like, $like);
    }
    return $where;
}

function kop_rinbox_drafts_count($view, $type) {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE " . kop_rinbox_drafts_where($view, $type));
}

function kop_rinbox_drafts_list(array $q) {
    global $wpdb;
    $view = (string) ($q['view'] ?? 'draft');
    $where = kop_rinbox_drafts_where($view, (string) ($q['filters']['type'] ?? ''), (string) ($q['search'] ?? ''));
    $order = $view === 'future' ? 'post_date ASC' : ($view === 'recent' ? 'post_date DESC' : 'post_modified DESC');
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where");
    $rows = $wpdb->get_results('SELECT ' . KOP_RINBOX_DRAFTS_COLS . " FROM {$wpdb->posts} WHERE $where
        ORDER BY $order, ID DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    return array('items' => array_map('kop_rinbox_drafts_item', (array) $rows), 'total' => $total);
}

/** A user's display name, '' when unknown. */
function kop_rinbox_drafts_user($id) {
    if ((int) $id <= 0 || !function_exists('get_userdata')) return '';
    $u = get_userdata((int) $id);
    return $u ? (string) $u->display_name : '';
}

/** The page or post as a reader would see it: shortcodes and block markup out. */
function kop_rinbox_drafts_plain($content) {
    $text = function_exists('strip_shortcodes') ? strip_shortcodes((string) $content) : preg_replace('/\[[^\]]*\]/', ' ', (string) $content);
    return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));
}

function kop_rinbox_drafts_item($p) {
    global $wpdb;
    $id = (int) $p->ID;
    $is_page = $p->post_type === 'page';
    $plain = kop_rinbox_drafts_plain($p->post_content);
    $words = $plain === '' ? 0 : count(preg_split('/\s+/u', $plain));

    $under = '';
    if ((int) $p->post_parent > 0) {
        $under = (string) $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", (int) $p->post_parent));
    }
    $cats = array();
    if (!$is_page && function_exists('get_the_category')) {
        foreach ((array) get_the_category($id) as $c) {
            if (is_object($c) && $c->name !== 'Uncategorized') $cats[] = (string) $c->name;
        }
    }
    $editor = kop_rinbox_drafts_user((int) get_post_meta($id, '_edit_last', true)) ?: kop_rinbox_drafts_user((int) $p->post_author);

    $labels = array('draft' => 'Draft', 'pending' => 'Pending review', 'future' => 'Scheduled', 'publish' => 'Published', 'trash' => 'Trashed', 'private' => 'Private');
    $sub = array_filter(array(
        KOP_RINBOX_DRAFT_TYPES[$p->post_type] ?? $p->post_type,
        $under !== '' ? 'Under ' . $under : '',
        $cats ? implode(', ', $cats) : '',
        'Last edited ' . substr((string) $p->post_modified, 0, 10) . ($editor !== '' ? ' by ' . $editor : ''),
    ));
    $details = array(array('label' => 'Words', 'value' => (string) $words));
    if ($p->post_status === 'future') $details[] = array('label' => 'Goes live', 'value' => substr((string) $p->post_date, 0, 16));
    if ($p->post_status === 'publish') $details[] = array('label' => 'Published', 'value' => substr((string) $p->post_date, 0, 16));
    if ($p->post_name !== '') $details[] = array('label' => 'Address', 'value' => '/' . $p->post_name . '/');

    $schedule = array('name' => 'when', 'label' => 'Date and time (site time, YYYY-MM-DD HH:MM)', 'type' => 'text',
        'value' => gmdate('Y-m-d', strtotime(current_time('mysql')) + DAY_IN_SECONDS) . ' 09:00');
    $actions = array();
    if ($p->post_status === 'draft' || $p->post_status === 'pending') {
        $actions[] = array('id' => 'publish', 'label' => 'Approve: publish now', 'style' => 'approve',
            'help' => 'Puts it on the site now, dated today. Back to draft (Published tab, or Undo in Recently done) takes it off again with its old dates.');
        $actions[] = array('id' => 'schedule', 'label' => 'Schedule', 'style' => 'neutral', 'params' => array($schedule),
            'help' => 'WordPress publishes it at that date and time. Back to draft on the Scheduled tab cancels it.');
        $actions[] = array('id' => 'trash', 'label' => 'Reject: trash', 'style' => 'reject',
            'help' => 'Moves it to the trash; Restore brings it back, and WordPress empties the trash after 30 days.');
    } elseif ($p->post_status === 'future') {
        $actions[] = array('id' => 'publish', 'label' => 'Publish now', 'style' => 'approve',
            'help' => 'Puts it on the site now instead of on its scheduled date.');
        $actions[] = array('id' => 'unpublish', 'label' => 'Back to draft', 'style' => 'undo',
            'help' => 'Cancels the schedule; it goes back to the status and dates it had.');
    } elseif ($p->post_status === 'publish') {
        $actions[] = array('id' => 'unpublish', 'label' => 'Back to draft', 'style' => 'undo',
            'help' => 'Takes it off the site; it goes back to the status and dates it had before it was published here (a draft, if it was published elsewhere).');
    } elseif ($p->post_status === 'trash') {
        $actions[] = array('id' => 'untrash', 'label' => 'Restore', 'style' => 'undo',
            'help' => 'Takes it out of the trash, back to the status it had.');
    }

    $preview = $p->post_status === 'publish'
        ? get_permalink($id)
        : (function_exists('get_preview_post_link') ? (string) get_preview_post_link($id)
            : home_url('/?' . ($is_page ? 'page_id' : 'p') . '=' . $id . '&preview=true'));
    $links = array(array('label' => 'Edit in WordPress', 'url' => admin_url('post.php?post=' . $id . '&action=edit')));
    if ($p->post_status === 'publish') $links[] = array('label' => 'View on the site', 'url' => $preview);

    $item = array(
        'key'          => (string) $id,
        'title'        => (string) $p->post_title !== '' ? (string) $p->post_title : '(no title)',
        'subtitle'     => implode(' · ', $sub),
        'url'          => $p->post_status === 'publish' ? $preview : '',
        'text'         => kop_rinbox_excerpt((string) $p->post_excerpt !== '' ? $p->post_excerpt : $plain, 600),
        'created'      => (string) $p->post_modified,
        'status'       => (string) $p->post_status,
        'status_label' => $labels[$p->post_status] ?? (string) $p->post_status,
        'details'      => $details,
        'fields'       => array(
            array('name' => 'title', 'label' => 'Title', 'type' => 'text', 'value' => (string) $p->post_title),
            array('name' => 'excerpt', 'label' => 'Excerpt (the summary shown in lists and link previews)', 'type' => 'textarea', 'value' => (string) $p->post_excerpt),
        ),
        'actions'      => $actions,
        'links'        => $links,
    );
    if ($p->post_status !== 'trash') $item['preview'] = array('label' => 'Preview', 'url' => $preview);
    return $item;
}

/** "YYYY-MM-DD HH:MM" in the site's time zone -> [local, gmt], or an error. */
function kop_rinbox_drafts_when($when) {
    $when = trim((string) $when);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{1,2}:\d{2})?$/', $when)) {
        throw new RuntimeException('Write the date as YYYY-MM-DD HH:MM, for example 2026-11-02 09:00.');
    }
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $dt = DateTime::createFromFormat('Y-m-d H:i', strpos($when, ' ') ? $when : $when . ' 09:00', $tz);
    if (!$dt) throw new RuntimeException('That is not a date.');
    $local = $dt->format('Y-m-d H:i:00');
    if ($local <= current_time('mysql')) throw new RuntimeException('Pick a time in the future; Publish now puts it on the site at once.');
    $dt->setTimezone(new DateTimeZone('UTC'));
    return array($local, $dt->format('Y-m-d H:i:00'));
}

function kop_rinbox_drafts_update(array $post, $what) {
    $res = wp_update_post($post, true);
    if (is_wp_error($res) || !$res) {
        throw new RuntimeException('WordPress would not ' . $what . (is_wp_error($res) ? ': ' . $res->get_error_message() : '.'));
    }
}

function kop_rinbox_drafts_act($key, $action, array $params) {
    $p = kop_rinbox_drafts_post($key);
    if (!$p) throw new RuntimeException('That page or post is gone.');
    $id = (int) $p->ID;
    $name = '"' . ($p->post_title !== '' ? $p->post_title : '#' . $id) . '"';
    $kind = strtolower(KOP_RINBOX_DRAFT_TYPES[$p->post_type] ?? 'post');
    // What Back to draft returns to: the draft as it was before the first publish or schedule.
    $remember = function () use ($p, $id) {
        if (in_array($p->post_status, array('draft', 'pending'), true)) {
            update_post_meta($id, '_kop_rinbox_was', wp_json_encode(array(
                'status' => $p->post_status, 'date' => $p->post_date, 'date_gmt' => $p->post_date_gmt)));
        }
    };
    switch ($action) {
        case 'publish':
            if (!in_array($p->post_status, array('draft', 'pending', 'future'), true)) throw new RuntimeException('Only a draft or a scheduled ' . $kind . ' can be published here.');
            $remember();
            kop_rinbox_drafts_update(array('ID' => $id, 'post_status' => 'publish', 'edit_date' => true,
                'post_date' => current_time('mysql'), 'post_date_gmt' => current_time('mysql', true)), 'publish it');
            kop_rinbox_flush_counts();
            return array('message' => 'Published ' . $name . '. It is on the Published tab, where Back to draft takes it off again.');
        case 'schedule':
            if (!in_array($p->post_status, array('draft', 'pending'), true)) throw new RuntimeException('Only a draft can be scheduled.');
            list($local, $gmt) = kop_rinbox_drafts_when($params['when'] ?? '');
            $remember();
            kop_rinbox_drafts_update(array('ID' => $id, 'post_status' => 'future', 'edit_date' => true,
                'post_date' => $local, 'post_date_gmt' => $gmt), 'schedule it');
            kop_rinbox_flush_counts();
            return array('message' => 'Scheduled ' . $name . ' for ' . substr($local, 0, 16) . ' (site time). Back to draft on the Scheduled tab cancels it.');
        case 'unpublish':
            if (!in_array($p->post_status, array('publish', 'future'), true)) throw new RuntimeException('It is not published or scheduled.');
            $was = json_decode((string) get_post_meta($id, '_kop_rinbox_was', true), true);
            $post = array('ID' => $id, 'post_status' => 'draft');
            if (is_array($was) && in_array($was['status'] ?? '', array('draft', 'pending'), true)) {
                $post = array('ID' => $id, 'post_status' => $was['status'], 'edit_date' => true,
                    'post_date' => (string) $was['date'], 'post_date_gmt' => (string) $was['date_gmt']);
            }
            kop_rinbox_drafts_update($post, 'take it off the site');
            delete_post_meta($id, '_kop_rinbox_was');
            kop_rinbox_flush_counts();
            return array('message' => $name . ' is ' . ($post['post_status'] === 'pending' ? 'waiting for review' : 'a draft') . ' again' . ($p->post_status === 'publish' ? ' and off the site.' : '; the schedule is cancelled.'));
        case 'trash':
            if (!in_array($p->post_status, array('draft', 'pending'), true)) throw new RuntimeException('Only a draft can be trashed here.');
            if (!wp_trash_post($id)) throw new RuntimeException('WordPress would not move it to the trash.');
            kop_rinbox_flush_counts();
            return array('message' => 'Moved ' . $name . ' to the trash. Restore (on the Trashed tab) brings it back; WordPress empties the trash after 30 days.');
        case 'untrash':
            if ($p->post_status !== 'trash') throw new RuntimeException('It is not in the trash.');
            // Back to the status it had before (WordPress would otherwise make it a draft).
            $hook = function_exists('wp_untrash_post_set_previous_status') ? 'wp_untrash_post_set_previous_status' : null;
            if ($hook) add_filter('wp_untrash_post_status', $hook, 10, 3);
            $ok = wp_untrash_post($id);
            if ($hook) remove_filter('wp_untrash_post_status', $hook, 10);
            if (!$ok) throw new RuntimeException('WordPress would not restore it.');
            kop_rinbox_flush_counts();
            return array('message' => 'Restored ' . $name . '.');
    }
    throw new RuntimeException('Unknown action.');
}

/** Title and excerpt, as the editor saves them. */
function kop_rinbox_drafts_save($key, array $fields) {
    $p = kop_rinbox_drafts_post($key);
    if (!$p) throw new RuntimeException('That page or post is gone.');
    $post = array('ID' => (int) $p->ID);
    if (array_key_exists('title', $fields)) {
        $title = sanitize_text_field((string) $fields['title']);
        if ($title === '') throw new RuntimeException('The title cannot be empty.');
        if ($title !== $p->post_title) $post['post_title'] = $title;
    }
    if (array_key_exists('excerpt', $fields)) {
        $excerpt = sanitize_textarea_field((string) $fields['excerpt']);
        if ($excerpt !== $p->post_excerpt) $post['post_excerpt'] = $excerpt;
    }
    if (count($post) === 1) return array('message' => 'Nothing to save.');
    kop_rinbox_drafts_update($post, 'save it');
    return array('message' => 'Saved.');
}
