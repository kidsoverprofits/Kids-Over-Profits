<?php
/**
 * Template Name: Volunteer
 * Description: /volunteer/ (2026-10-23, replacing the hub page's editor copy).
 * Four ways to help as cards (2026-10-25, owner: the page was cluttered and long): review state inspection
 * findings and research a question (both through the one sign-up form under the cards, inc/volunteer-signup.php;
 * research questions are inc/research-tasks.php, picked on the volunteer's page), answer from experience
 * (/questions/, inc/questions.php, no sign-up), send what you have (the submission tools, folded). The skills we
 * need sit at the foot, then the call for sensitivity readers with its survey (2026-10-25, inc/sensitivity-survey.php). Each card's words are a block admins edit in place (kop_ie_html_start(),
 * inc/inline-edit.php). The opening line is kop_hub_config('volunteer') (inc/hub-shell.php).
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
// Research questions on offer to volunteers (inc/research-tasks.php), shown on the research card.
$kop_vp_open = function_exists('kop_rt_counts') && get_option('kop_rt_db') ? (int) kop_rt_counts()['unassigned'] : 0;
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
        </header>

        <?php // Four ways to help, one card each: what it is in a sentence, how long, one button. Details fold. ?>
        <div class="kop-volpage-ways">
            <section class="kop-volpage-way" aria-labelledby="kop-volpage-review-h">
                <?php kop_ie_html_start('volunteer:review', 'Volunteer page: review inspection findings'); ?>
                <h2 class="kop-volpage-way-h" id="kop-volpage-review-h">Review inspection findings</h2>
                <p>Our scanner pulls passages out of state inspection reports. You decide: harm or danger to a young person, or paperwork?</p>
                <p class="kop-volpage-meta">Needs your own link (no account). A 10-minute practice round first.</p>
                <?php kop_ie_html_end(); ?>
                <p class="kop-volpage-go"><a class="kop-volpage-btn" href="#kop-vol-signup">Get a link and start</a></p>
            </section>

            <section class="kop-volpage-way" aria-labelledby="kop-volpage-research-h">
                <?php kop_ie_html_start('volunteer:research', 'Volunteer page: research a question'); ?>
                <h2 class="kop-volpage-way-h" id="kop-volpage-research-h">Research a question</h2>
                <p>Pick a question our records cannot answer yet, such as when a program closed or who ran it, and look it up. Each one says where to start.</p>
                <p class="kop-volpage-meta">Needs your own link (no account). Every fact you send needs the link to where you found it.</p>
                <?php kop_ie_html_end(); ?>
                <p class="kop-volpage-go"><a class="kop-volpage-btn" href="#kop-vol-signup">Get a link and pick one</a><?php
                    if ($kop_vp_open > 0) echo ' <span class="kop-volpage-count">' . esc_html(number_format_i18n($kop_vp_open)) . ' open</span>'; ?></p>
            </section>

            <section class="kop-volpage-way" id="kop-volpage-questions" aria-labelledby="kop-volpage-questions-h">
                <?php kop_ie_html_start('volunteer:questions', 'Volunteer page: answer our questions'); ?>
                <h2 class="kop-volpage-way-h" id="kop-volpage-questions-h">Answer from experience</h2>
                <p>Were you in a program, did you work at one or send a child to one? Answer short questions about it, one at a time.</p>
                <p class="kop-volpage-meta">No link needed. Stop after one answer if you like.</p>
                <?php kop_ie_html_end(); ?>
                <p class="kop-volpage-go"><a class="kop-volpage-btn" href="<?php echo esc_url($kop_vp_questions); ?>">Answer our questions</a></p>
            </section>

            <section class="kop-volpage-way" id="kop-volpage-send" aria-labelledby="kop-volpage-send-h">
                <?php kop_ie_html_start('volunteer:send', 'Volunteer page: send us what you have'); ?>
                <h2 class="kop-volpage-way-h" id="kop-volpage-send-h">Send us what you have</h2>
                <p>Records, letters, photos, news stories, court cases or corrections. Much of our archive came from survivors and families.</p>
                <p class="kop-volpage-meta">No link needed. Uploads can be anonymous.</p>
                <details class="kop-volpage-more">
                    <summary>Choose what to send</summary>
                    <ul class="kop-volpage-list">
                        <li><a href="<?php echo esc_url($kop_vp_links['upload']); ?>">Upload documents anonymously</a>: encrypted as soon as they reach our server.</li>
                        <li><a href="<?php echo esc_url($kop_vp_links['data']); ?>">Tell us about a program</a>: add or correct what we hold.</li>
                        <li><a href="<?php echo esc_url($kop_vp_links['news']); ?>">Send a news story</a></li>
                        <li><a href="<?php echo esc_url($kop_vp_links['lawsuit']); ?>">Send a lawsuit</a></li>
                        <li><a href="<?php echo esc_url($kop_vp_links['legislation']); ?>">Send a bill or law</a></li>
                        <li><a href="<?php echo esc_url($kop_vp_links['wiki']); ?>">Write for the r/troubledteens wiki</a></li>
                    </ul>
                    <p>Each tool has a tutorial: the &ldquo;?&rdquo; button in the lower left corner. A mistake on the site? The flag button beside it tells us.</p>
                </details>
                <?php kop_ie_html_end(); ?>
            </section>
        </div>

        <?php
        // The one sign-up for reviewing and research: the form, its content warning and the link it makes.
        if (function_exists('kop_vols_module')) {
            kop_vols_module();
        }
        ?>

        <section class="kop-volpage-section" id="kop-volpage-skills" aria-labelledby="kop-volpage-skills-h">
            <?php kop_ie_html_start('volunteer:skills', 'Volunteer page: skills we need'); ?>
            <h2 class="kop-volpage-way-h" id="kop-volpage-skills-h">Have a skill to give?</h2>
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

        <?php
        // Sensitivity readers: the communities are Dropdown Lists 'sensitivity-groups'; the survey goes to
        // KOP Tools > Sensitivity Survey (inc/sensitivity-survey.php). No drafts to read yet, so the survey comes first.
        $kop_vp_groups = function_exists('kop_sensitivity_groups') ? kop_sensitivity_groups('offered') : array();
        $kop_vp_js = get_stylesheet_directory() . '/js/sensitivity-survey.js';
        if (file_exists($kop_vp_js)) {
            wp_enqueue_script('kop-sensitivity-survey', get_stylesheet_directory_uri() . '/js/sensitivity-survey.js', array(), filemtime($kop_vp_js), true);
        }
        ?>
        <section class="kop-volpage-section" id="kop-volpage-sensitivity" aria-labelledby="kop-volpage-sensitivity-h">
            <h2 class="kop-volpage-way-h" id="kop-volpage-sensitivity-h">Be a sensitivity reader</h2>
            <?php kop_ie_html_start('volunteer:sensitivity-intro', 'Volunteer page: sensitivity readers'); ?>
                <p>Much of what we report happened to young people from communities the industry, and the systems that feed it,
                    treat worst. If you belong to one of them, tell us where our reporting falls short: what we have missed, what we
                    get wrong, and what we would need to know to write about it well. We are looking for:</p>
            <?php kop_ie_html_end(); ?>
            <ul class="kop-volpage-list">
                <?php foreach ($kop_vp_groups as $kop_vp_label) : ?>
                    <li><?php echo esc_html($kop_vp_label); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php kop_ie_html_start('volunteer:sensitivity-how', 'Volunteer page: sensitivity readers, how it works'); ?>
                <p>Start with the survey below. Later we will ask readers who want to keep going to read articles before they are
                    published. Only the Kids Over Profits team reads your answers, and we name readers only if they ask to be named.</p>
            <?php kop_ie_html_end(); ?>

            <details class="kop-volpage-more kop-sens" id="kop-sens-survey">
                <summary>Take the survey</summary>
                <form class="kop-vols kop-sens-form" data-rest="<?php echo esc_url(rest_url('kop/v1/volunteer/sensitivity-survey')); ?>" novalidate>
                    <p class="kop-vols-help">Answer as much or as little as you like: one question is enough.</p>
                    <fieldset class="kop-vols-field">
                        <legend>Which community are you answering for?</legend>
                        <?php foreach ($kop_vp_groups as $kop_vp_value => $kop_vp_label) : ?>
                            <label class="kop-sens-check"><input type="checkbox" name="groups" value="<?php echo esc_attr($kop_vp_value); ?>"> <?php echo esc_html($kop_vp_label); ?></label>
                        <?php endforeach; ?>
                        <label for="kop-sens-other" class="kop-sens-sub">Another community</label>
                        <input type="text" id="kop-sens-other" name="group_other" maxlength="200">
                    </fieldset>
                    <div class="kop-vols-field">
                        <label for="kop-sens-gaps">What is missing from our coverage of your community?</label>
                        <p class="kop-vols-help">Programs, cases, people or patterns we have not written about, or have written about too little.</p>
                        <textarea id="kop-sens-gaps" name="gaps" rows="5"></textarea>
                    </div>
                    <div class="kop-vols-field">
                        <label for="kop-sens-harm">Is anything offensive or harmful that nobody has dealt with?</label>
                        <p class="kop-vols-help">On our site, or said and done widely among survivors and advocates.</p>
                        <textarea id="kop-sens-harm" name="harm" rows="5"></textarea>
                    </div>
                    <div class="kop-vols-field">
                        <label for="kop-sens-context">What history or culture should we know?</label>
                        <p class="kop-vols-help">For example, a practice that means something different inside your community than it looks
                            from outside, or older history that explains what is happening now.</p>
                        <textarea id="kop-sens-context" name="context" rows="5"></textarea>
                    </div>
                    <div class="kop-vols-field">
                        <label for="kop-sens-pages">Pages on our site that got it wrong <span class="kop-vols-opt">(optional)</span></label>
                        <p class="kop-vols-help">Links or page names, and what is wrong with them.</p>
                        <textarea id="kop-sens-pages" name="pages" rows="3"></textarea>
                    </div>
                    <div class="kop-vols-field">
                        <label for="kop-sens-more">Anything else? <span class="kop-vols-opt">(optional)</span></label>
                        <p class="kop-vols-help">Including people, groups or writing we should talk to or read.</p>
                        <textarea id="kop-sens-more" name="more" rows="3"></textarea>
                    </div>
                    <div class="kop-vols-field">
                        <label for="kop-sens-name">Your name or a nickname <span class="kop-vols-opt">(optional)</span></label>
                        <input type="text" id="kop-sens-name" name="name" maxlength="100" autocomplete="nickname">
                    </div>
                    <div class="kop-vols-field">
                        <label class="kop-sens-check"><input type="checkbox" name="read_later" value="1"> Ask me to read drafts later</label>
                        <label for="kop-sens-email" class="kop-sens-sub">Email <span class="kop-vols-opt">(needed only to be asked later)</span></label>
                        <input type="email" id="kop-sens-email" name="email" maxlength="190" autocomplete="email">
                        <p class="kop-vols-help">Never shown to anyone else.</p>
                    </div>
                    <div class="kop-vols-trap" aria-hidden="true">
                        <label for="kop-sens-website">Leave this empty</label>
                        <input type="text" id="kop-sens-website" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    <p class="kop-vols-error" role="alert" hidden></p>
                    <button type="submit" class="kop-vols-btn">Send my answers</button>
                </form>
                <div class="kop-vols kop-sens-done" hidden tabindex="-1">
                    <p class="kop-vols-done-h">Thank you. Your answers reached us.</p>
                    <p>We read every one. If you asked to read drafts, we will write when there is something to read.</p>
                </div>
            </details>
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
