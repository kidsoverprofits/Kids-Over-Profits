<?php
/**
 * Template Name: Volunteer
 * Description: /volunteer/ (2026-10-23, replacing the hub page's editor copy).
 * The ways to help, in the order a newcomer can start them: sign up and review
 * state inspection findings now (the sign-up form, inc/volunteer-signup.php),
 * answer short questions about what the records lack (/questions/,
 * inc/questions.php), send what you have through the submission tools, and the
 * skills we need. Each section is a block admins edit in place
 * (kop_ie_html_start(), inc/inline-edit.php). The opening line and the buttons
 * under the title are kop_hub_config('volunteer') (inc/hub-shell.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

foreach (array('trail', 'hub', 'volunteer-page') as $kop_vp_css) {
    $kop_vp_path = get_stylesheet_directory() . '/css/' . $kop_vp_css . '.css';
    if (file_exists($kop_vp_path)) {
        wp_enqueue_style('kop-' . $kop_vp_css, get_stylesheet_directory_uri() . '/css/' . $kop_vp_css . '.css', array(), filemtime($kop_vp_path));
    }
}

$kop_vp_url = function ($template, $fallback) {
    return function_exists('kop_hub_page_url') ? kop_hub_page_url($template, $fallback) : home_url($fallback);
};
$kop_vp_questions = function_exists('kop_q_url') ? kop_q_url() : home_url('/questions/');
$kop_vp_links = array(
    'data'        => $kop_vp_url('page-data.php', '/tti-data-submission/'),
    'upload'      => home_url('/anon-submit/'),
    'news'        => $kop_vp_url('page-news-processor.php', '/news-processor/'),
    'legislation' => $kop_vp_url('page-submit-legislation.php', '/submit-legislation/'),
    'lawsuit'     => $kop_vp_url('page-submit-lawsuit.php', '/submit-lawsuit/'),
    'wiki'        => $kop_vp_url('page-wiki-editor.php', '/wiki-editor/'),
);

get_header();

while (have_posts()) :
    the_post();
    $kop_vp_config = function_exists('kop_hub_config') ? kop_hub_config('volunteer') : array();
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-hub kop-volpage'); ?>>
    <div class="entry-content-wrap">

        <?php
        if (function_exists('kop_article_breadcrumbs')) {
            kop_article_breadcrumbs('volunteer', get_the_title());
        }
        ?>

        <header class="entry-header page-title kop-hub-header">
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <?php if (!empty($kop_vp_config['standfirst'])) : ?>
                <p class="kop-hub-standfirst"><?php echo esc_html($kop_vp_config['standfirst']); ?></p>
            <?php endif; ?>
            <?php
            if (function_exists('kop_hub_actions')) {
                kop_hub_actions('volunteer');
            }
            ?>
        </header>

        <?php
        // Start now: the sign-up form, its content warning and the link it makes.
        if (function_exists('kop_vols_module')) {
            kop_vols_module();
        }
        ?>

        <section class="kop-volpage-section" id="kop-volpage-questions" aria-labelledby="kop-volpage-questions-h">
            <?php kop_ie_html_start('volunteer:questions', 'Volunteer page: answer our questions'); ?>
            <h2 class="kop-hub-h" id="kop-volpage-questions-h">Answer our questions</h2>
            <p>If you were in a program, worked at one or sent a child to one, you know things our records do not. Our questions
                page asks one short question at a time: when a program opened or closed, what town it was in, whether staff used
                restraint or a level system, whether someone worked there when you did.</p>
            <p>There is nothing to sign up for, and you can stop after one answer. When three people give the same answer and
                nobody gives another, it goes into our records.</p>
            <?php kop_ie_html_end(); ?>
            <p><a class="kop-volpage-btn" href="<?php echo esc_url($kop_vp_questions); ?>">Answer our questions</a></p>
        </section>

        <section class="kop-volpage-section" id="kop-volpage-send" aria-labelledby="kop-volpage-send-h">
            <?php kop_ie_html_start('volunteer:send', 'Volunteer page: send us what you have'); ?>
            <h2 class="kop-hub-h" id="kop-volpage-send-h">Send us what you have</h2>
            <p>Much of our archive was sent in by survivors and families. Each of these tools has a tutorial: press the
                &ldquo;?&rdquo; button in the lower left corner.</p>
            <ul class="kop-volpage-list">
                <li><a href="<?php echo esc_url($kop_vp_links['data']); ?>">Tell us about a program</a>: add or correct what we hold on a facility.</li>
                <li><a href="<?php echo esc_url($kop_vp_links['upload']); ?>">Upload documents anonymously</a>: records, letters or photos,
                    encrypted as soon as they reach our server.</li>
                <li><a href="<?php echo esc_url($kop_vp_links['news']); ?>">Send a news story</a> about a program or the industry.</li>
                <li><a href="<?php echo esc_url($kop_vp_links['lawsuit']); ?>">Send a lawsuit</a> against a program or its staff.</li>
                <li><a href="<?php echo esc_url($kop_vp_links['legislation']); ?>">Send a bill or law</a> about the industry.</li>
                <li><a href="<?php echo esc_url($kop_vp_links['wiki']); ?>">Write for the r/troubledteens wiki</a>.</li>
            </ul>
            <p>Found a mistake on the site? Press the flag button beside it to tell us.</p>
            <?php kop_ie_html_end(); ?>
        </section>

        <section class="kop-volpage-section" id="kop-volpage-skills" aria-labelledby="kop-volpage-skills-h">
            <?php kop_ie_html_start('volunteer:skills', 'Volunteer page: skills we need'); ?>
            <h2 class="kop-hub-h" id="kop-volpage-skills-h">Skills we need</h2>
            <p>Kids Over Profits is a nonprofit documenting the troubled teen industry. If you have one of these skills, write to
                us at <a href="mailto:action@kidsoverprofits.org">action@kidsoverprofits.org</a>, even if you only have a few hours.</p>
            <ul class="kop-volpage-list">
                <li><strong>Legal advice:</strong> legal questions, case law research and compliance in our advocacy.</li>
                <li><strong>Web design:</strong> improving how the site looks and works.</li>
                <li><strong>Graphic design:</strong> visuals for our campaigns.</li>
                <li><strong>Fundraising:</strong> finding funding and planning campaigns.</li>
                <li><strong>Research:</strong> programs, licensing and incidents.</li>
                <li><strong>Transcription:</strong> interviews, survivor stories and legal documents.</li>
                <li><strong>Social media:</strong> making posts and running our accounts.</li>
                <li><strong>Data organization:</strong> keeping our records in order.</li>
                <li><strong>Translation:</strong> our materials for survivors who speak little or no English.</li>
            </ul>
            <?php kop_ie_html_end(); ?>
        </section>

        <footer class="kop-hub-footer">
            <div class="kop-hub-footer-meta">
                <span>Updated <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>"><?php echo esc_html(get_the_modified_date()); ?></time></span>
                <?php edit_post_link('Edit this page', '<span class="kop-hub-edit">', '</span>'); ?>
            </div>
            <?php
            if (function_exists('kop_hub_share')) {
                kop_hub_share();
            }
            ?>
        </footer>

    </div>
</article>
    <?php
endwhile;

get_footer();
