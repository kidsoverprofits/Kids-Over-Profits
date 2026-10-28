<?php
/**
 * A category related to the troubled teen industry (/related/<category>/):
 * every record of it, by state. Not a selectable page template:
 * inc/related-facilities.php routes the request here with the category in
 * $GLOBALS['kop_related_list']. Each record links its own page (the facility
 * page, labelled with the category).
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_rc = $GLOBALS['kop_related_list'];
$kop_rc_groups = kop_related_list_groups($kop_rc['category']);
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
