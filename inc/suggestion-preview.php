<?php
/**
 * Preview of a waiting data suggestion on its facility page.
 *
 * Submissions Review's data card ("What changed", js/admin-submissions.js
 * renderChanges()) links each facility on file to
 *   /facility/<slug>/?kop_sugg_preview=<suggestion id>&entry=<facility index>
 * For an admin that page is drawn from the record as Approve would leave it
 * (kop_suggestion_preview_doc() in api/lib-suggestion-changes.php), with a bar
 * across the top saying it is a preview. Nothing is written; the edit pencils
 * are off (kop_ie_can()), and the page is never cached or indexed.
 * Everyone else gets the live page: the parameter is ignored.
 *
 * The swap itself is one line in kop_facility_page_data() (inc/facility-pages.php):
 * $GLOBALS['kop_facility_doc_override'][<facility id>] replaces the stored document.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_sugg_preview_start')) {
    /** Before the facility route (priority 0) builds the page: load the suggestion and set the override. */
    function kop_sugg_preview_start() {
        if (!isset($_GET['kop_sugg_preview']) || is_admin()) return;
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;
        $id = (int) $_GET['kop_sugg_preview'];
        $index = isset($_GET['entry']) ? (int) $_GET['entry'] : 0;
        $state = array('id' => $id, 'facility_id' => 0, 'error' => '');
        $GLOBALS['kop_sugg_preview'] = &$state;

        nocache_headers();
        do_action('litespeed_control_set_nocache', 'kop suggestion preview');
        if (!headers_sent()) header('X-Robots-Tag: noindex, nofollow');

        if (!function_exists('kop_seed_pdo')) {
            $state['error'] = 'The database is not reachable from this page.';
            return;
        }
        $pdo = kop_seed_pdo();
        if (!$pdo instanceof PDO) {
            $state['error'] = 'The database is not reachable from this page.';
            return;
        }
        require_once get_stylesheet_directory() . '/api/lib-suggested-edits.php';
        global $wpdb;
        $prefix = isset($wpdb->prefix) ? $wpdb->prefix : '';
        try {
            $table = kop_resolve_table_name($pdo, 'suggested_edits', $prefix);
            $stmt = $pdo->prepare("SELECT status, edited_json_data, reason FROM `{$table}` WHERE id = ? LIMIT 1");
            $stmt->execute(array($id));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $state['error'] = 'Suggestion #' . $id . ' was not found.';
                return;
            }
            if ((string) $row['status'] !== 'pending') {
                $state['error'] = 'Suggestion #' . $id . ' is no longer waiting (' . $row['status'] . '), so this is the page as it is now.';
                return;
            }
            $built = kop_suggestion_preview_doc($pdo, json_decode((string) $row['edited_json_data'], true), $index, (string) ($row['reason'] ?? ''), $prefix);
        } catch (Throwable $e) {
            error_log('kop_sugg_preview_start: ' . $e->getMessage());
            $state['error'] = 'The preview could not be built: ' . $e->getMessage();
            return;
        }
        if (!$built) {
            $state['error'] = 'This part of suggestion #' . $id . ' is about no record on file, so there is no page to preview.';
            return;
        }
        $state['facility_id'] = (int) $built['facility_id'];
        $GLOBALS['kop_facility_doc_override'][$state['facility_id']] = $built['doc'];
    }
    add_action('template_redirect', 'kop_sugg_preview_start', -1);
}

if (!function_exists('kop_sugg_preview_bar')) {
    /** The bar across the top of a preview: what it is, the live page, back to the review. */
    function kop_sugg_preview_bar() {
        $state = $GLOBALS['kop_sugg_preview'] ?? null;
        if (!is_array($state)) return;
        $page = $GLOBALS['kop_facility_page'] ?? null;
        $page_id = is_array($page) ? (int) ($page['id'] ?? 0) : 0;
        $error = $state['error'];
        if ($error === '' && $page_id !== $state['facility_id']) {
            $error = 'Suggestion #' . $state['id'] . ' is about another record, so this is the page as it is now.';
        }
        $live = remove_query_arg(array('kop_sugg_preview', 'entry'));
        $review = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-admin-submissions.php') : '';
        ?>
        <style>
            .kop-sugg-preview-bar { position: sticky; top: 0; z-index: 9999; background: var(--kop-midnight-blue, #000435); color: #fff; padding: 10px 16px; font-size: 15px; line-height: 1.5; border-bottom: 4px solid var(--kop-orange, #EF9034); }
            .kop-sugg-preview-bar.is-error { border-bottom-color: var(--kop-coral-pink, #FE8088); }
            .kop-sugg-preview-bar strong { color: #fff; }
            .kop-sugg-preview-bar a.kop-spb-link,
            .kop-sugg-preview-bar a.kop-spb-link:hover,
            .kop-sugg-preview-bar a.kop-spb-link:focus,
            .kop-sugg-preview-bar a.kop-spb-link:active { color: #fff; background: transparent; text-decoration: underline; margin-left: 12px; white-space: nowrap; }
            body.admin-bar .kop-sugg-preview-bar { top: 32px; }
            @media (max-width: 782px) { body.admin-bar .kop-sugg-preview-bar { top: 0; } }
        </style>
        <div class="kop-sugg-preview-bar<?php echo $error !== '' ? ' is-error' : ''; ?>" role="status">
            <?php if ($error !== '') : ?>
                <strong>No preview:</strong> <?php echo esc_html($error); ?>
            <?php else : ?>
                <strong>Preview, not saved:</strong> this page as it will look if you approve suggestion #<?php echo (int) $state['id']; ?>. Editing is off here.
            <?php endif; ?>
            <a class="kop-spb-link" href="<?php echo esc_url($live); ?>">Live page</a>
            <?php if ($review !== '') : ?><a class="kop-spb-link" href="<?php echo esc_url($review); ?>">Back to Submissions Review</a><?php endif; ?>
        </div>
        <?php
    }
    add_action('wp_body_open', 'kop_sugg_preview_bar', 1);
}

if (!function_exists('kop_sugg_preview_robots')) {
    function kop_sugg_preview_robots($robots) {
        if (!empty($GLOBALS['kop_sugg_preview'])) $robots['noindex'] = true;
        return $robots;
    }
    add_filter('wp_robots', 'kop_sugg_preview_robots');
}
