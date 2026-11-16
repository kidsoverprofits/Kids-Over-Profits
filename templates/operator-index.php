<?php
/**
 * /operator/: every parent company with a page (inc/operator-pages.php routes
 * here with $GLOBALS['kop_operator_index'], rows from
 * kop_operator_history_index_rows()). Operating companies, then those closed,
 * merged or acquired; every company a card, the major ones first (owner, Claude
 * note #13: no flat list under the cards).
 * Uses the facility profile stylesheet.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_oi = isset($GLOBALS['kop_operator_index']) && is_array($GLOBALS['kop_operator_index']) ? $GLOBALS['kop_operator_index'] : array('rows' => array(), 'title' => 'Parent companies', 'summary' => '');
// Operating companies first, then those closed, merged or acquired
// (kop_operator_history_is_active()); in each, the major ones first, then the rest, all as cards.
$kop_oi_groups = array(
    'active'  => array('title' => 'Operating companies', 'note' => 'Companies still running programs, or with no closure on record.', 'major' => array(), 'other' => array()),
    'defunct' => array('title' => 'Closed, merged or acquired', 'note' => 'Companies that have shut down or been taken over, or whose programs on record have all closed. Their pages keep the history.', 'major' => array(), 'other' => array()),
);
foreach ($kop_oi['rows'] as $r) {
    $kop_oi_groups[!isset($r['active']) || $r['active'] ? 'active' : 'defunct'][$r['major'] ? 'major' : 'other'][] = $r;
}
$kop_oi_bits = static function (array $r) {
    $bits = array();
    if ($r['programs'] > 0) $bits[] = $r['programs'] . ' ' . ($r['programs'] === 1 ? 'program' : 'programs') . ($r['open'] ? ', ' . $r['open'] . ' open' : '');
    if ($r['states'] + $r['countries'] > 1) $bits[] = kop_operator_place_phrase($r['states'], $r['countries']);
    if ($r['years'] !== '') $bits[] = $r['years'];
    if ($r['status'] !== '' && strcasecmp($r['status'], 'Active') !== 0) $bits[] = $r['status'];
    return $bits;
};

get_header();
?>
<article class="entry content-bg single-entry kop-facility-profile kop-facility-generated kop-operator-profile kop-operator-index" data-kop-bug-feature="operator-index" data-kop-bug-label="Parent companies index">

    <header class="kop-fp-header">
        <p class="kop-fp-eyebrow">Who runs the programs</p>
        <h1 class="entry-title kop-fp-title"><?php echo esc_html($kop_oi['title']); ?></h1>
        <p class="kop-fp-formerly"><?php echo esc_html($kop_oi['summary']); ?></p>
    </header>

    <div class="kop-fp-body kop-fp-generated-body kop-oi-body">

        <p class="kop-fp-count">Each company page lists the programs the company has run, its history year by year, the people who ran it, news, lawsuits, deaths on record and the documents filed under it and its programs.</p>

        <?php foreach ($kop_oi_groups as $kop_oi_key => $g) : if (!$g['major'] && !$g['other']) continue; ?>
        <section class="kop-fp-section" id="<?php echo esc_attr($kop_oi_key); ?>">
            <h2><?php echo esc_html($g['title']); ?></h2>
            <p class="kop-fp-count"><?php echo esc_html(count($g['major']) + count($g['other'])); ?> companies. <?php echo esc_html($g['note']); ?></p>
            <ul class="kop-oi-cards">
                <?php foreach (array_merge($g['major'], $g['other']) as $r) : ?>
                    <li class="kop-oi-card">
                        <h3><a href="<?php echo esc_url($r['url']); ?>"><?php echo esc_html($r['name']); ?></a></h3>
                        <?php $bits = $kop_oi_bits($r); if ($bits) : ?><p class="kop-oi-meta"><?php echo esc_html(implode(' | ', $bits)); ?></p><?php endif; ?>
                        <?php if ($r['lede'] !== '') : ?><p class="kop-oi-lede"><?php echo esc_html($r['lede']); ?></p><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>

        <section class="kop-fp-section" id="contribute">
            <h2>Know something we do not?</h2>
            <p class="kop-fp-rail-text">Ownership changes, executives, documents and first-hand accounts go into the review queue and are checked before they are published.</p>
            <p class="kop-fp-rail-actions">
                <a class="kop-fp-rail-link" href="<?php echo esc_url(home_url('/tti-data-submission/')); ?>">Submit information</a>
            </p>
        </section>

    </div>

</article>
<?php
get_footer();
