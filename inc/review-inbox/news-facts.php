<?php
/**
 * Review inbox source: facts the news reader (inc/news-facts.php) found that
 * have no facility card to go on: about a company, a person, an agency, or a
 * program the article is not linked to. Facts about a linked program are on
 * the Woodbury Facts cards (From: News articles) instead.
 *
 * Keep marks a fact for its record (each kind of record gets its own Add);
 * Drop leaves the site as it is. Both have Undo. The tool turns automatic
 * approval of the plain facility facts (staff, years, size, ages, gender,
 * type) on or off (kop_nf_set_auto()).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('news-facts', function () {
    if (!function_exists('kop_nf_pdo') || !kop_nf_pdo()) return null;
    $pdo = kop_nf_pdo();
    try {
        kop_nf_ensure_tables($pdo);
    } catch (Throwable $e) {
        error_log('kop news facts tables: ' . $e->getMessage());
        return null;
    }
    $cats = array();
    foreach (kop_nf_categories() as $k => $c) $cats[$k] = $c[0];
    return array(
        'label'    => 'Facts from news',
        'group'    => 'Imports to review',
        'area'     => 'scans',
        'help'     => 'What approved news articles say about a company, a person, an agency or a program the article is not linked to, read newest first, six articles an hour. '
            . 'Facts about a program the article is linked to are on Woodbury Facts (From: News articles), with Add and Undo. Each fact here shows the article\'s own words; '
            . 'survivors\' names are always taken out. Keep marks it for its record; Drop leaves the site as it is. '
            . 'Automatic approval of staff, opening years, size, ages, gender and type is ' . (kop_nf_auto_on() ? 'on' : 'off (until the first articles have been checked by hand)') . '.',
        'views'    => kop_nf_statuses(),
        'filters'  => array(array('name' => 'category', 'label' => 'Kind', 'options' => $cats)),
        'tools'    => array(
            array('id' => 'auto', 'label' => kop_nf_auto_on() ? 'Turn automatic approval off' : 'Turn automatic approval on', 'style' => 'neutral',
                'help' => kop_nf_auto_on()
                    ? 'Staff, years, size, ages, gender and type from the news wait for you again; what was already added stays, with Undo.'
                    : 'Staff, opening years, size, ages, gender and type whose words were found in the article are added every hour when nothing on the record disagrees, held for the write-up like any approval; each has Undo on Woodbury Facts > Approved automatically.'),
            array('id' => 'pause', 'label' => get_option('kop_news_facts_paused') ? 'Start reading again' : 'Pause the reader', 'style' => 'neutral',
                'help' => 'Stops or restarts the hourly reading of articles. Nothing already found changes.'),
        ),
        'tool'     => function ($tool, array $params = array()) {
            if ($tool === 'auto') {
                $on = !kop_nf_auto_on();
                $n = kop_nf_set_auto($on);
                return array('message' => $on ? "Automatic approval is on: {$n} waiting facts will be added within the hour where nothing disagrees."
                    : "Automatic approval is off: {$n} facts wait for you again.");
            }
            if ($tool === 'pause') {
                $paused = !get_option('kop_news_facts_paused');
                update_option('kop_news_facts_paused', $paused ? 1 : 0, false);
                return array('message' => $paused ? 'The reader is paused.' : 'The reader starts again within the hour.');
            }
            throw new RuntimeException('Unknown tool.');
        },
        'tool_url' => admin_url('admin.php?page=kop-woodbury-facts'),
        'count'    => function () {
            return (int) kop_nf_counts(kop_nf_pdo())['pending'];
        },
        'view_counts' => function (array $q = array()) {
            return kop_nf_counts(kop_nf_pdo());
        },
        'list'     => function (array $q) {
            $status = isset(kop_nf_statuses()[$q['view']]) ? $q['view'] : 'pending';
            $res = kop_nf_rows(kop_nf_pdo(), $status, (string) $q['search'], (int) $q['offset'], (int) $q['limit'],
                (string) ($q['filters']['category'] ?? ''));
            return array('items' => array_map('kop_rinbox_nf_item', $res['rows']), 'total' => $res['total']);
        },
        'get'      => function ($key) {
            $row = kop_nf_row(kop_nf_pdo(), (int) $key);
            return $row ? kop_rinbox_nf_item($row) : null;
        },
        'act'      => 'kop_rinbox_nf_act',
    );
});

function kop_rinbox_nf_item(array $r) {
    $cats = kop_nf_categories();
    $statuses = kop_nf_statuses();
    $label = $cats[$r['category']][0] ?? $r['category'];
    $standing = kop_nf_standings()[$r['standing']] ?? '';
    $details = array();
    $details[] = array('label' => 'About', 'value' => $r['subject_name'] . ($r['subject_kind'] !== '' ? ' (' . $r['subject_kind'] . ')' : ''));
    if ($r['person'] !== '') $details[] = array('label' => 'Person', 'value' => $r['person'] . ($r['role'] !== '' ? ', ' . $r['role'] : ''));
    if ($r['value'] !== '' && $r['value'] !== null) $details[] = array('label' => 'Value', 'value' => (string) $r['value']);
    if ($r['event_date'] !== '') $details[] = array('label' => 'When', 'value' => $r['event_date']);
    if ($standing !== '') $details[] = array('label' => 'Standing', 'value' => $standing);
    if (trim((string) $r['quote']) !== '') {
        $details[] = array('label' => 'The article\'s words', 'value' => '"' . $r['quote'] . '"'
            . ((int) $r['quote_found'] ? '' : ' (these words were not found in the article text: check the article before keeping)'));
    }
    if ((int) $r['names_removed']) $details[] = array('label' => 'Survivor', 'value' => 'A survivor\'s name was taken out of this fact.');
    if ($r['status'] !== 'pending') $details[] = array('label' => $statuses[$r['status']] ?? $r['status'], 'value' => trim(substr((string) $r['reviewed_at'], 0, 10) . ' by ' . $r['reviewed_by']));

    $actions = array();
    if ($r['status'] === 'pending') {
        $actions[] = array('id' => 'keep', 'label' => 'Approve: keep for the record', 'style' => 'approve',
            'help' => 'Marks the fact as right. It goes onto the company, person or program record when that kind of record gets its Add from this list; nothing on the site changes yet.');
        $actions[] = array('id' => 'drop', 'label' => 'Reject: drop', 'style' => 'reject',
            'help' => 'Nothing on the site changes; the fact moves to Dropped.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => 'Back to waiting', 'style' => 'undo',
            'help' => 'Nothing on the site changes; the fact waits again.');
    }
    $date = (string) ($r['publication_date'] ?? '');
    return array(
        'key'          => (string) (int) $r['id'],
        'title'        => $label . ': ' . $r['summary'],
        'subtitle'     => implode(' · ', array_filter(array((string) $r['publication_name'], '"' . $r['article_title'] . '"', $date))),
        'url'          => (string) $r['article_url'],
        'text'         => '',
        'created'      => (string) $r['created_at'],
        'status'       => (string) $r['status'],
        'status_label' => $statuses[$r['status']] ?? (string) $r['status'],
        'fields'       => array(),
        'details'      => $details,
        'links'        => array(array('label' => 'The article', 'url' => (string) $r['article_url'])),
        'actions'      => $actions,
    );
}

function kop_rinbox_nf_act($key, $action, array $params) {
    $pdo = kop_nf_pdo();
    $who = kop_rinbox_reviewer();
    kop_rinbox_flush_counts();
    $to = array('keep' => 'kept', 'drop' => 'dropped', 'undo' => 'pending');
    if (!isset($to[$action])) throw new RuntimeException('Unknown action.');
    return array('message' => kop_nf_decide($pdo, (int) $key, $to[$action], $who));
}
