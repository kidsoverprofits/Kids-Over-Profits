<?php
/**
 * /volunteer-review/: volunteers recommend approve or reject on review inbox
 * items through their personal link (inc/review-volunteers.php). Drawn by
 * js/volunteer-review.js; without a working link it says how to get one.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_vol = kop_vol_current();
$kop_vol_preview = !$kop_vol && current_user_can('manage_options');
$kop_vol_projects = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-volunteers.php') : '';
// "How this works" is open until they have made a call or played a practice round.
$kop_vol_new = $kop_vol && function_exists('kop_vol_has_started') && !kop_vol_has_started((int) $kop_vol['id']);
$kop_vol_stall = function_exists('kop_volp_sweep_rules') ? (int) kop_volp_sweep_rules()['stall_days'] : 3;
$kop_vol_signup = home_url('/volunteer/#kop-vol-signup');

get_header();
?>
<div class="kop-vol-page">
    <section class="kop-vol-panel kop-vol-intro">
        <h1>Volunteer work</h1>
        <?php if ($kop_vol || $kop_vol_preview) : ?>
            <p><?php echo $kop_vol ? 'Hi ' . esc_html($kop_vol['name']) . ', thank you for helping.' : 'Admin preview: this is what volunteers see. Recommending is turned off for you here.'; ?></p>
            <p>There are four kinds of work, and every one of them helps: answering research questions about programs, finding pictures for
                our pages, making social media posts from what we add to the site, and checking findings from state inspection reports. Pick
                any of them below, and switch whenever you like. An admin checks everything before it changes the site or goes out.</p>
            <p class="kop-vol-meta">The inspection findings are the hard reading: they describe abuse in plain words, and their card says so.
                Take breaks; you never have to finish anything.</p>
            <details class="kop-vol-howto"<?php echo $kop_vol_new || $kop_vol_preview ? ' open' : ''; ?>>
                <summary>How this works</summary>
                <h2>Getting started</h2>
                <p>Under <em>Choose your work</em>, pick any of the four. Research questions: tick the ones you want and take them.
                    Pictures: five pages at a time. Social media posts: press <em>Ask for this work</em> and we set you up; the work then
                    shows up on this page.</p>
                <p>Inspection findings start with a practice round: 10 findings an admin has already decided, with the decision shown after
                    each one. Get 8 of them the same and your first 10 real ones open. On each, choose <em>Recommend approve</em>,
                    <em>Recommend reject</em> or <em>Not sure</em>; not sure is a good answer when the note says why.</p>
                <h2>Choosing your next work</h2>
                <p>The four kinds of work stay on this page under <em>Choose more work</em> while you have something going. When a batch
                    is done, <em>Give me 10 more</em> starts the next one.</p>
                <p>Only a few minutes? <a href="<?php echo esc_url(function_exists('kop_q_url') ? kop_q_url() : home_url('/questions/')); ?>">Answer our
                    questions</a>: one short question at a time about what our records are missing.</p>
                <h2>Taking a break or stopping</h2>
                <p>A break needs nothing from you. A batch with no answers for <?php echo (int) $kop_vol_stall; ?> days goes back so others can
                    pick it up, and your link keeps working. <em>Hand this batch back</em>, on any batch, returns it at once.</p>
                <p><em>Stop volunteering</em>, at the bottom of this page, turns your link off and returns your open batches. To come back,
                    <a href="<?php echo esc_url($kop_vol_signup); ?>">get a new link</a>.</p>
                <h2>Questions</h2>
                <p>Every item and batch has <em>Ask a question</em>. We answer at the bottom of this page.</p>
            </details>
        <?php elseif (!empty($GLOBALS['kop_vol_bad_invite'])) : ?>
            <p>This link does not work any more. It may have been replaced with a new one. Ask the person who sent it for a fresh link.</p>
        <?php else : ?>
            <p>This page is for volunteers with a personal invite link. Open the link you were sent on this device and you will be signed in here, with no password.</p>
            <?php if ($kop_vol_projects) : ?>
                <p>Want to help? See our <a href="<?php echo esc_url($kop_vol_projects); ?>">volunteer opportunities</a>.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php if ($kop_vol || $kop_vol_preview) : ?>
        <div id="kop-vol-app" class="kop-vol-app">
            <section class="kop-vol-panel"><p role="status">Loading…</p></section>
        </div>
        <noscript><section class="kop-vol-panel"><p>This page needs JavaScript turned on.</p></section></noscript>
    <?php endif; ?>
</div>
<?php
get_footer();
