<?php
/**
 * KOP Tools > Card Add-ons: which review inbox add-on ("Home of a program",
 * "File under a young adult program", ...) shows on which queue's cards.
 *
 * One row per queue, one column per add-on (kop_rinbox_addon() in
 * inc/review-inbox.php). A box starts as the code's default (the add-on's
 * 'default', or the queue listing it in 'addons'); what the owner changes is
 * kept in the kop_review_addons option, only where it differs from that
 * default, so a new queue still gets the default. A queue an add-on can never
 * be on ('never': about something else, or doing it its own way) shows a dash.
 * "Put back the defaults" empties the option. Tested by
 * scripts/review-inbox-tests/addons.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** On by default for this queue, before the owner's choice: the queue's 'addons', else the add-on's 'default'. */
function kop_rinbox_addon_default($id, $source) {
    if (!kop_rinbox_addon_allowed($id, $source)) return false;
    $src = kop_rinbox_sources()[$source] ?? array();
    if (in_array($id, (array) ($src['addons'] ?? array()), true)) return true;
    return (bool) (kop_rinbox_addons()[$id]['default'] ?? false);
}

/**
 * Save the screen's boxes: $on = [add-on id => [queue => '1']] for every
 * ticked box. Keeps only the choices that differ from the default.
 * Returns how many boxes now differ from the default.
 */
function kop_rinbox_addon_save(array $on) {
    $choices = array();
    foreach (kop_rinbox_addons() as $id => $a) {
        foreach (array_keys(kop_rinbox_sources()) as $source) {
            if (!kop_rinbox_addon_allowed($id, $source)) continue;
            $want = !empty($on[$id][$source]);
            if ($want !== kop_rinbox_addon_default($id, $source)) $choices[$id][$source] = $want;
        }
    }
    update_option('kop_review_addons', $choices, false);
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
    return array_sum(array_map('count', $choices));
}

function kop_rinbox_addons_admin_menu() {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Card Add-ons', 'Card Add-ons', 'manage_options', 'kop-card-addons', 'kop_rinbox_addons_page');
}
add_action('admin_menu', 'kop_rinbox_addons_admin_menu', 30);

function kop_rinbox_addons_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_addons_save'])) {
        check_admin_referer('kop_card_addons');
        if (!empty($_POST['kop_addons_reset'])) {
            delete_option('kop_review_addons');
            $message = 'Every queue is back to the defaults.';
        } else {
            $on = isset($_POST['on']) && is_array($_POST['on']) ? wp_unslash($_POST['on']) : array();
            $n = kop_rinbox_addon_save($on);
            $message = 'Saved. ' . ($n ? $n . ' box' . ($n === 1 ? '' : 'es') . ' differ from the defaults.' : 'Every box is at its default.');
        }
    }
    $addons = kop_rinbox_addons();
    $groups = array();
    foreach (kop_rinbox_sources() as $key => $src) $groups[(string) ($src['group'] ?? 'Other queues')][$key] = $src;
    $inbox = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-admin-submissions.php') : '';
    ?>
    <div class="wrap">
        <h1>Card Add-ons</h1>
        <p style="max-width:780px">Actions written once that can sit on any review inbox queue's cards. Tick a box to show the add-on on that queue's cards,
            untick it to take it off. A dash means the add-on cannot go on that queue (its cards are about something else, or the queue does the same
            thing its own way). Taking an add-on off a queue never takes back what it already did: Undo stays in Recently done.
            <?php if ($inbox !== '') : ?><a href="<?php echo esc_url($inbox); ?>">Open the Review Inbox</a>.<?php endif; ?></p>
        <?php if ($message !== '') : ?><div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div><?php endif; ?>
        <?php if (!$addons) : ?>
            <p>No add-ons are installed.</p>
        <?php else : ?>
        <table class="widefat" style="max-width:1100px;margin-bottom:12px">
            <thead><tr><th>Add-on</th><th>What it does on a card</th></tr></thead>
            <tbody>
            <?php foreach ($addons as $id => $a) : ?>
                <tr><td><strong><?php echo esc_html($a['label']); ?></strong></td><td><?php echo esc_html($a['help']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post">
            <?php wp_nonce_field('kop_card_addons'); ?>
            <input type="hidden" name="kop_addons_save" value="1">
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th>Queue</th>
                    <?php foreach ($addons as $id => $a) : ?><th style="text-align:center"><?php echo esc_html($a['label']); ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($groups as $group => $list) : ?>
                    <tr><th colspan="<?php echo 1 + count($addons); ?>" style="background:#f0f0f1"><?php echo esc_html($group); ?></th></tr>
                    <?php foreach ($list as $key => $src) : ?>
                        <tr>
                            <td><?php echo esc_html($src['label'] ?? $key); ?></td>
                            <?php foreach ($addons as $id => $a) : ?>
                                <td style="text-align:center">
                                <?php if (!kop_rinbox_addon_allowed($id, $key)) : ?>
                                    <span title="Not for this queue" aria-label="Not for this queue">&ndash;</span>
                                <?php else :
                                    $on = kop_rinbox_addon_on($id, $key);
                                    $changed = $on !== kop_rinbox_addon_default($id, $key); ?>
                                    <label><input type="checkbox" name="on[<?php echo esc_attr($id); ?>][<?php echo esc_attr($key); ?>]" value="1" <?php checked($on); ?>
                                        aria-label="<?php echo esc_attr($a['label'] . ' on ' . ($src['label'] ?? $key)); ?>">
                                        <?php if ($changed) : ?><span style="font-size:11px">(changed)</span><?php endif; ?></label>
                                <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="submit" class="button button-primary">Save</button>
                <button type="submit" class="button" name="kop_addons_reset" value="1"
                    onclick="return confirm('Put every queue back to the defaults?');">Put back the defaults</button>
            </p>
        </form>
        <?php endif; ?>
    </div>
    <?php
}
