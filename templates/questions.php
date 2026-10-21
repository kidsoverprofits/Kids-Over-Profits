<?php
/**
 * /questions/: "We have questions about..." (inc/questions.php routes here).
 * The cards are drawn by js/questions.js from kop/v1/questions/*; styled by
 * css/questions.css, phone first.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<article class="entry content-bg single-entry kop-questions" data-kop-bug-feature="questions" data-kop-bug-label="We have questions">
    <div class="entry-content-wrap">
        <header class="kop-q-header">
            <h1 class="entry-title">We have questions</h1>
            <p class="kop-q-lede">Were you in a troubled teen program, did you work at one, or did you send your child to one? Our records have gaps, and sometimes our sources disagree. Answer what you know and skip what you don't.</p>
            <p class="kop-q-small">No account, no name and no email needed. We check every answer before it goes on the site.</p>
        </header>

        <section class="kop-q-start" id="kop-q-start" aria-label="Choose what to answer">
            <label class="kop-q-label" for="kop-q-find">A program or company you know</label>
            <div class="kop-q-findwrap">
                <input type="search" id="kop-q-find" class="kop-q-input" placeholder="Start typing a name" autocomplete="off" aria-controls="kop-q-results">
                <ul class="kop-q-results" id="kop-q-results" role="listbox" hidden></ul>
            </div>
            <p class="kop-q-or">or</p>
            <label class="kop-q-label" for="kop-q-state">Questions about programs in</label>
            <div class="kop-q-row">
                <select id="kop-q-state" class="kop-q-input"><option value="">Any state</option></select>
                <button type="button" class="kop-q-btn kop-q-btn-primary" id="kop-q-go">Start</button>
            </div>
        </section>

        <section class="kop-q-deck" id="kop-q-deck" aria-live="polite" hidden></section>

        <noscript><p class="kop-q-small">This page needs JavaScript. You can also use the <a href="<?php echo esc_url(home_url('/tti-data-submission/')); ?>">data form</a>.</p></noscript>
    </div>
</article>
<?php
get_footer();
