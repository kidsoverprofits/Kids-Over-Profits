<?php
/**
 * A category related to the troubled teen industry (/related/<category>/):
 * every record of it, by state. Not a selectable page template:
 * inc/related-facilities.php routes the request here with the category in
 * $GLOBALS['kop_related_list']. Each record links its own page (the facility
 * page, labelled with the category). Articles about the industry as a whole
 * (no one record) are listed under "In the news".
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_rc = $GLOBALS['kop_related_list'];
$kop_rc_groups = kop_related_list_groups($kop_rc['category']);
// Articles about the industry as a whole, filed from the Review inbox (kop_related_news(); Claude note #26).
$kop_rc_news = function_exists('kop_related_news') ? kop_related_news($kop_rc['category']) : array();
$kop_rc_count = 0;
foreach ($kop_rc_groups as $g) $kop_rc_count += count($g['items']);

if (function_exists('kop_page_trail_set')) kop_page_trail_set(array());
get_header();
?>
<article class="entry content-bg single-entry kop-facility-profile kop-related-category">
    <header class="kop-fp-header">
        <?php echo function_exists('kop_page_trail_html') ? kop_page_trail_html($kop_rc['label']) : ''; ?>
        <p class="kop-fp-eyebrow">Related to the troubled teen industry <span aria-hidden="true">&middot;</span> Not troubled teen programs</p>
        <h1 class="entry-title kop-fp-title"><?php echo esc_html($kop_rc['label']); ?></h1>
    </header>

    <div class="kop-fp-body">
        <section class="kop-fp-section" id="about">
            <p><?php echo esc_html($kop_rc['intro']); ?></p>
            <p class="kop-fp-count"><?php echo esc_html($kop_rc_count === 1 ? '1 on record.' : $kop_rc_count . ' on record.'); ?></p>
        </section>

        <?php if ($kop_rc_news) : ?>
        <section class="kop-fp-section" id="news">
            <h2>In the news</h2>
            <ul class="kop-fp-news">
                <?php foreach ($kop_rc_news as $n) :
                    $has_url = $n['url'] !== '' && preg_match('#^https?://#i', $n['url']);
                    $outlet = $n['outlet'] !== '' ? $n['outlet'] : ($has_url ? (string) preg_replace('/^www\./', '', (string) wp_parse_url($n['url'], PHP_URL_HOST)) : '');
                    $initial = $outlet !== '' ? strtoupper(mb_substr(preg_replace('/^(?:The|A)\s+/i', '', $outlet), 0, 1)) : '';
                    ?>
                    <li class="kop-fp-news-item"<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('rec:news:' . (int) $n['id'], 'this article') : ''; ?>>
                        <span class="kop-fp-news-thumb kop-fp-news-thumb--none" aria-hidden="true"><span class="kop-fp-news-initial"><?php echo esc_html($initial); ?></span></span>
                        <div class="kop-fp-news-body">
                            <p class="kop-fp-news-meta">
                                <?php if ($outlet !== '') : ?><span class="kop-fp-news-outlet"><?php echo esc_html($outlet); ?></span><?php endif; ?>
                                <?php if ($n['date_label'] !== '') : ?><span><?php echo esc_html($n['date_label']); ?></span><?php endif; ?>
                            </p>
                            <h3 class="kop-fp-news-title">
                                <?php if ($has_url) : ?>
                                    <a href="<?php echo esc_url($n['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($n['title']); ?></a><?php if ($n['archive'] !== '' && preg_match('#^https?://#i', $n['archive'])) : ?> <a class="kop-archived-link" href="<?php echo esc_url($n['archive']); ?>" target="_blank" rel="noopener noreferrer">archived copy</a><?php endif; ?>
                                <?php else : ?>
                                    <?php echo esc_html($n['title']); ?>
                                <?php endif; ?>
                            </h3>
                            <?php if ($n['summary'] !== '') : ?><p class="kop-fp-news-summary"><?php echo esc_html($n['summary']); ?></p><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php foreach ($kop_rc_groups as $g) : ?>
        <section class="kop-fp-section" id="<?php echo esc_attr(sanitize_title($g['place'])); ?>">
            <h2><?php echo esc_html($g['place']); ?></h2>
            <ul class="kop-fp-records kop-fp-siblings">
                <?php foreach ($g['items'] as $it) :
                    $bits = array_filter(array($it['city'], $it['years'], ($it['status'] !== '' && $it['status'] !== 'Unknown') ? $it['status'] : ''), 'strlen');
                    ?>
                    <li<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('facility:' . (int) $it['id'] . ':all', $it['name']) : ''; ?>>
                        <a href="<?php echo esc_url($it['url']); ?>"><?php echo esc_html($it['name']); ?></a>
                        <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                        <?php if ($it['operator'] !== '') : ?>
                            <span class="meta">Run by <?php echo $it['operator_url'] !== '' ? '<a href="' . esc_url($it['operator_url']) . '">' . esc_html($it['operator']) . '</a>' : esc_html($it['operator']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>

        <?php if (!$kop_rc_groups) : ?>
        <section class="kop-fp-section"><p>No records yet.</p></section>
        <?php endif; ?>
    </div>
</article>
<?php
get_footer();
