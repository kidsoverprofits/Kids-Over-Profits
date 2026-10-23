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
            <p>Our scanner reads state inspection reports on troubled teen programs and pulls out passages that may be serious violations.
                It cannot tell who did what to whom, or whether a child was hurt. You read each passage, open the full report when it is
                unclear, and tell us whether it is a serious violation: harm or danger to a young person, not paperwork. An admin makes every
                final call. Approved violations are published on the site, and the most serious on our home page and the inspection reports hub.</p>
            <div class="kop-vol-warning" role="note">
                <p><strong>Before you start: this is hard reading.</strong> The reports describe what was done to children in these programs:
                    physical and sexual abuse, restraint, injuries, self-harm, neglect and deaths. Inspectors write it plainly, and the full
                    reports give more detail.</p>
                <p>Take breaks. Skip any item with <em>Skip for now</em>, hand a batch back, or start with other work instead. You never
                    have to finish anything.</p>
            </div>
            <details class="kop-vol-howto"<?php echo $kop_vol_new || $kop_vol_preview ? ' open' : ''; ?>>
                <summary>How this works</summary>
                <h2>Getting started</h2>
                <p>Press <em>Start now</em>. You get a practice round: 10 findings an admin has already decided, with the decision shown after
                    each one. Get 8 of them the same and your first 10 real findings open straight away.</p>
                <p>On each one, choose <em>Recommend approve</em>, <em>Recommend reject</em> or <em>Not sure</em>. Not sure is a good answer
                    when the note says why. Nothing changes on the site until an admin checks your call.</p>
                <h2>Choosing your next work</h2>
                <p>When a batch is done, <em>Give me 10 more</em> starts the next one. Under <em>Learn another queue</em>, a practice round
                    opens other work: news stories, court cases, program closures and more. Each opens to you once you pass its round.</p>
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
