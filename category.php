<?php
/**
 * Category archive: a solid panel with the category's name and description,
 * a search box scoped to the category (?cs=, inc/category-archive.php), links
 * to the matching hub and the main hubs, the posts as cards and pagination.
 * Replaces the parent theme's default archive, which printed bare cards on
 * the gradient background.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_ca_cat   = get_queried_object();
$kop_ca_term  = function_exists('kop_category_archive_term') ? kop_category_archive_term() : '';
$kop_ca_hubs  = function_exists('kop_category_archive_hubs') ? kop_category_archive_hubs($kop_ca_cat) : array();
$kop_ca_title = single_cat_title('', false);
$kop_ca_base  = $kop_ca_cat ? get_category_link($kop_ca_cat) : home_url('/');

if (function_exists('kop_page_trail_set') && function_exists('kop_article_page')) {
    $kop_ca_steps = array();
    $kop_ca_news  = kop_article_page('researchreports');
    if ($kop_ca_news) {
        $kop_ca_steps[] = array('title' => wp_specialchars_decode($kop_ca_news['title']), 'url' => $kop_ca_news['url']);
    }
    if ($kop_ca_cat && $kop_ca_cat->parent) {
        $kop_ca_parent = get_term($kop_ca_cat->parent, 'category');
        if ($kop_ca_parent && !is_wp_error($kop_ca_parent)) {
            $kop_ca_steps[] = array('title' => $kop_ca_parent->name, 'url' => get_category_link($kop_ca_parent));
        }
    }
    kop_page_trail_set($kop_ca_steps);
}

get_header();
?>
<div id="primary" class="content-area kop-category" data-kop-bug-feature="category-archive" data-kop-bug-label="Category archive">
    <main id="main" class="site-main kop-category__panel">
        <?php if (function_exists('kop_page_trail_html')) { echo kop_page_trail_html($kop_ca_title); } ?>

        <header class="kop-category__header">
            <h1 class="kop-category__title"><?php echo esc_html($kop_ca_title); ?></h1>
            <?php $kop_ca_desc = category_description(); ?>
            <?php if ($kop_ca_desc) : ?>
                <div class="kop-category__standfirst"><?php echo wp_kses_post($kop_ca_desc); ?></div>
            <?php endif; ?>
        </header>

        <form class="kop-category__search" role="search" action="<?php echo esc_url($kop_ca_base); ?>" method="get">
            <label class="kop-category__search-label" for="kop-category-search">Search <?php echo esc_html($kop_ca_title); ?></label>
            <div class="kop-category__search-row">
                <input id="kop-category-search" class="kop-category__search-input" type="search" name="cs"
                       value="<?php echo esc_attr($kop_ca_term); ?>" placeholder="A program, company, place or topic">
                <button type="submit" class="kop-category__search-button">Search</button>
            </div>
            <p class="kop-category__search-note">
                <?php if ($kop_ca_term !== '') : ?>
                    Showing <?php echo (int) $wp_query->found_posts; ?> in this category matching &ldquo;<?php echo esc_html($kop_ca_term); ?>&rdquo;.
                    <a href="<?php echo esc_url($kop_ca_base); ?>">Clear search</a>.
                <?php endif; ?>
                To search every page and record, use the <a href="<?php echo esc_url(add_query_arg('s', rawurlencode($kop_ca_term), home_url('/'))); ?>">whole-site search</a>.
            </p>
        </form>

        <?php if (have_posts()) : ?>
            <ul class="kop-category__list">
                <?php while (have_posts()) : the_post(); ?>
                    <li class="kop-category__card">
                        <?php if (has_post_thumbnail()) : ?>
                            <a class="kop-category__thumb" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
                                <?php the_post_thumbnail('medium', array('loading' => 'lazy', 'alt' => '')); ?>
                            </a>
                        <?php endif; ?>
                        <div class="kop-category__body">
                            <h2 class="kop-category__card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <p class="kop-category__meta"><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time></p>
                            <p class="kop-category__excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt(), 38)); ?></p>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
            <?php
            the_posts_pagination(array(
                'mid_size'  => 1,
                'prev_text' => 'Newer',
                'next_text' => 'Older',
                'class'     => 'kop-category__pagination',
            ));
            ?>
        <?php else : ?>
            <p class="kop-category__empty">
                <?php echo $kop_ca_term !== '' ? 'Nothing in this category matches that search.' : 'Nothing has been filed under this category yet.'; ?>
            </p>
        <?php endif; ?>

        <?php if ($kop_ca_hubs) : ?>
            <nav class="kop-category__hubs" aria-labelledby="kop-category-hubs-title">
                <h2 id="kop-category-hubs-title" class="kop-category__hubs-title">Related hubs</h2>
                <ul class="kop-category__hub-list">
                    <?php foreach ($kop_ca_hubs as $hub) : ?>
                        <li>
                            <a class="kop-category__hub" href="<?php echo esc_url($hub['url']); ?>">
                                <span class="kop-category__hub-name"><?php echo esc_html($hub['title']); ?></span>
                                <span class="kop-category__hub-note"><?php echo esc_html($hub['note']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </main>
</div>
<?php
get_footer();
