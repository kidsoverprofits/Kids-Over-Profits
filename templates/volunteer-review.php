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
        <h1>Volunteer review</h1>
        <?php if ($kop_vol || $kop_vol_preview) : ?>
            <p><?php echo $kop_vol ? 'Hi ' . esc_html($kop_vol['name']) . ', thank you for helping.' : 'Admin preview: this is what volunteers see. Recommending is turned off for you here.'; ?></p>
            <p>There are four kinds of work here, and you choose below: checking serious violations our scanner pulls out of state inspection
                reports, answering research questions about programs, making social media posts from what we add to the site, and finding
                pictures for our pages. An admin checks everything before it changes the site or goes out.</p>
            <div class="kop-vol-warning" role="note">
                <p><strong>Before you start: this is hard reading.</strong> Much of this work describes what was done to children in these programs:
                    physical and sexual abuse, restraint, injuries, self-harm, neglect and deaths. The inspection findings are the most direct:
                    inspectors write it plainly, and the full reports give more detail.</p>
                <p>Take breaks. Skip any item with <em>Skip for now</em>, hand a batch back, or choose other work. You never
                    have to finish anything.</p>
            </div>
            <details class="kop-vol-howto"<?php echo $kop_vol_new || $kop_vol_preview ? ' open' : ''; ?>>
                <summary>How this works</summary>
                <h2>Getting started</h2>
                <p>Under <em>Choose your work</em>, pick one. The inspection findings start with a practice round: 10 findings an admin has
                    already decided, with the decision shown after each one. Get 8 of them the same and your first 10 real ones open straight
                    away. On each, choose <em>Recommend approve</em>, <em>Recommend reject</em> or <em>Not sure</em>; not sure is a good answer
                    when the note says why.</p>
                <p>Research questions need no practice round: tick the ones you want and take them. Pictures start straight away: five pages
                    at a time. For social media posts, press <em>Ask for this work</em> and we set you up; the work then shows up on this page.</p>
                <h2>Choosing your next work</h2>
                <p>When a batch is done, <em>Give me 10 more</em> starts the next one. The other kinds of work stay one click away under
                    <em>Other work</em>.</p>
                <p>Only a few minutes? <a href="<?php echo esc_url(function_exists('kop_q_url') ? kop_q_url() : home_url('/questions/')); ?>">Answer our
                    questions</a>: one short question at a time about what our records are missing.</p>
                <h2>Taking a break or stopping</h2>
                <p>A break needs nothing from you. A batch with no answers for <?php echo (int) $kop_vol_stall; ?> days goes back so others can
                    pick it up, and your link keeps working. <em>Hand this batch back</em>, on any batch, returns it at once.</p>
                <p><em>Stop volunteering</em>, at the bottom of this page, turns your link off and returns your open batches. To come back,
                    <a href="<?php echo esc_url($kop_vol_signup); ?>">sign up again</a>.</p>
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
