<?php
/**
 * Nonprofit tax filings (IRS Form 990) on facility and company pages.
 *
 * Many programs are nonprofits, and a nonprofit's yearly return is public:
 * revenue, spending, assets, what its officers and directors were paid. The
 * pipeline (kop-pipeline scripts/nonprofit-990.py) finds the organizations
 * whose name is one of our records' names, in the IRS's list of exempt
 * organizations and, for closed programs, in ProPublica's Nonprofit Explorer,
 * and writes them to js/data/nonprofit-filings/ (kop-data):
 *
 *   candidates.json    {built, organizations: [{ein, name, dba, city, state, ntee, subsection,
 *                       match (matched | check), flags, candidates: [{kind (facility | operator),
 *                       id, how, name, place, status, same_town}], filings, years, latest_year,
 *                       latest_revenue}]}
 *   orgs/<ein>.json    {ein, name, address, city, state, zip, ntee, subsection, ruling (YYYY-MM),
 *                       url, filings: [{year, period, form, pdf, revenue, expenses, assets,
 *                       liabilities, officer_pay, other_salaries, program_revenue, gifts_grants}]}
 *
 * The owner confirms each organization at Review inbox > Nonprofit tax
 * filings (inc/review-inbox/nonprofit-filings.php): Link ties it to a facility
 * record or a company, Not this program leaves it off. A link is a row of
 * {prefix}kop_nonprofit_links (one organization can run several programs, one
 * program can have filed under two organizations over the years); the pages
 * read the organization's file at render time, so a new build of the files
 * shows at once. Decisions: option kop_nonprofit_review, ein => {decision
 * (linked | rejected), by, at, links: [link ids]}; Undo deletes exactly those
 * link rows.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_NPF_DB_VERSION = '1';

function kop_npf_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_nonprofit_links';
}

function kop_npf_install($force = false) {
    if (!$force && get_option('kop_npf_db') === KOP_NPF_DB_VERSION) return;
    global $wpdb;
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_npf_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ein CHAR(9) NOT NULL,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        operator_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY ein (ein),
        KEY facility_id (facility_id),
        KEY operator_id (operator_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_npf_db', KOP_NPF_DB_VERSION, false);
}
add_action('admin_init', 'kop_npf_install');

function kop_npf_ready() {
    static $ready = null;
    if ($ready === null) {
        global $wpdb;
        $ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', kop_npf_table())) === kop_npf_table();
    }
    return $ready;
}

/* ---- The pipeline's files ------------------------------------------------ */

function kop_npf_dir() {
    return get_stylesheet_directory() . '/js/data/nonprofit-filings';
}

/** The organizations the pipeline found, ein => entry. */
function kop_npf_candidates() {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = array();
    $file = kop_npf_dir() . '/candidates.json';
    $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    foreach ((array) ($data['organizations'] ?? array()) as $e) {
        if (!is_array($e) || !preg_match('/^\d{9}$/', (string) ($e['ein'] ?? ''))) continue;
        // Records merged since the file was built: the one each went into.
        if (function_exists('kop_facility_merge_follow_candidates')) $e['candidates'] = kop_facility_merge_follow_candidates($e['candidates'] ?? array());
        $memo[(string) $e['ein']] = $e;
    }
    return $memo;
}

/** An organization's filings (orgs/<ein>.json), or null. */
function kop_npf_org($ein) {
    static $memo = array();
    $ein = (string) $ein;
    if (!preg_match('/^\d{9}$/', $ein)) return null;
    if (!array_key_exists($ein, $memo)) {
        $file = kop_npf_dir() . '/orgs/' . $ein . '.json';
        $org = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $memo[$ein] = is_array($org) && !empty($org['ein']) ? $org : null;
    }
    return $memo[$ein];
}

/** "23-6437695". */
function kop_npf_ein_label($ein) {
    $ein = (string) $ein;
    return strlen($ein) === 9 ? substr($ein, 0, 2) . '-' . substr($ein, 2) : $ein;
}

/** What kind of organization the IRS files it as (the NTEE code's major group). */
function kop_npf_ntee_label($code) {
    static $groups = array(
        'A' => 'Arts and culture', 'B' => 'Education', 'C' => 'Environment', 'D' => 'Animal welfare', 'E' => 'Health care',
        'F' => 'Mental health and crisis services', 'G' => 'Disease and disorders', 'H' => 'Medical research', 'I' => 'Crime and legal',
        'J' => 'Employment', 'K' => 'Food and agriculture', 'L' => 'Housing and shelter', 'M' => 'Public safety and disaster relief',
        'N' => 'Recreation and sports', 'O' => 'Youth development', 'P' => 'Human services', 'Q' => 'International affairs',
        'R' => 'Civil rights and advocacy', 'S' => 'Community improvement', 'T' => 'Philanthropy and grantmaking',
        'U' => 'Science and technology', 'V' => 'Social science', 'W' => 'Public and societal benefit', 'X' => 'Religion',
        'Y' => 'Mutual and membership benefit', 'Z' => 'Unknown',
    );
    $c = strtoupper(substr(trim((string) $code), 0, 1));
    return $groups[$c] ?? '';
}

/** "$5,422,125", or '' when the return does not give it. */
function kop_npf_money($v) {
    if ($v === null || $v === '') return '';
    $v = (int) $v;
    return ($v < 0 ? '-$' : '$') . number_format(abs($v));
}

/* ---- Links --------------------------------------------------------------- */

/** Link rows, newest first: ['ein' => ...] or ['facility_id' => ...] or ['operator_id' => ...]. */
function kop_npf_links(array $where) {
    if (!kop_npf_ready()) return array();
    global $wpdb;
    $col = key($where);
    if (!in_array($col, array('ein', 'facility_id', 'operator_id', 'id'), true)) return array();
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_npf_table() . " WHERE {$col} = %s ORDER BY id DESC", (string) current($where)), ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/** The page address of a linked record, for LiteSpeed. */
function kop_npf_record_url($kind, $id) {
    if ($kind === 'operator') return function_exists('kop_operator_page_url') ? (string) kop_operator_page_url((int) $id) : '';
    return function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $id) : '';
}

function kop_npf_purge($kind, $id) {
    $url = kop_npf_record_url($kind, $id);
    if ($url !== '' && function_exists('do_action')) do_action('litespeed_purge_url', $url);
}

/** The record's name, for messages and cards. */
function kop_npf_record_name($kind, $id) {
    global $wpdb;
    if ($kind === 'operator') {
        $name = $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . $wpdb->prefix . 'kop_operators WHERE id = %d', (int) $id));
        return $name ? (string) $name : 'company #' . (int) $id;
    }
    $f = function_exists('kop_rinbox_facility') ? kop_rinbox_facility((int) $id) : null;
    return $f ? (string) $f['name'] : 'facility #' . (int) $id;
}

/** Does the record exist? */
function kop_npf_record_exists($kind, $id) {
    global $wpdb;
    $table = $kind === 'operator' ? $wpdb->prefix . 'kop_operators' : 'facilities_v2';
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id = %d", (int) $id)) > 0;
}

/** Add a link. Returns the new row id, or 0 when the record already has this organization. */
function kop_npf_add_link($ein, $kind, $id, $reviewer) {
    global $wpdb;
    $col = $kind === 'operator' ? 'operator_id' : 'facility_id';
    foreach (kop_npf_links(array('ein' => $ein)) as $l) {
        if ((int) $l[$col] === (int) $id) return 0;
    }
    $wpdb->insert(kop_npf_table(), array(
        'ein' => (string) $ein, 'facility_id' => $kind === 'operator' ? 0 : (int) $id, 'operator_id' => $kind === 'operator' ? (int) $id : 0,
        'created_by' => substr((string) $reviewer, 0, 60), 'created_at' => gmdate('Y-m-d H:i:s'),
    ));
    kop_npf_purge($kind, $id);
    return (int) $wpdb->insert_id;
}

/* ---- Review decisions ----------------------------------------------------- */

function kop_npf_decisions() {
    $saved = get_option('kop_nonprofit_review', array());
    return is_array($saved) ? $saved : array();
}

function kop_npf_save_decisions(array $d) {
    update_option('kop_nonprofit_review', $d, false);
}

/** Link organization $ein to a facility ($kind 'facility') or company ('operator'). Returns the message. */
function kop_npf_apply($ein, $kind, $id, $reviewer) {
    $ein = (string) $ein;
    $kind = $kind === 'operator' ? 'operator' : 'facility';
    $id = (int) $id;
    if (!isset(kop_npf_candidates()[$ein])) throw new RuntimeException('That organization is not in the list any more.');
    if (!kop_npf_ready()) throw new RuntimeException('The links table is not set up yet: open any admin page once and try again.');
    if ($id <= 0) throw new RuntimeException($kind === 'operator' ? 'Pick the company.' : 'Pick the record.');
    if (!kop_npf_record_exists($kind, $id)) throw new RuntimeException(($kind === 'operator' ? 'Company' : 'Record') . ' #' . $id . ' is not on file.');
    $decisions = kop_npf_decisions();
    $d = $decisions[$ein] ?? array();
    if (($d['decision'] ?? '') === 'rejected') unset($decisions[$ein], $d);
    $link = kop_npf_add_link($ein, $kind, $id, $reviewer);
    $name = kop_npf_record_name($kind, $id);
    if (!$link) return $name . ' already has this organization; nothing changed.';
    $links = array_merge((array) ($d['links'] ?? array()), array($link));
    $decisions[$ein] = array('decision' => 'linked', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'links' => $links);
    kop_npf_save_decisions($decisions);
    return 'Linked to ' . $name . '. Its ' . ($kind === 'operator' ? 'company' : 'facility') . ' page lists the filings now; Undo is on the Linked tab.';
}

function kop_npf_reject($ein, $reviewer) {
    $ein = (string) $ein;
    if (!isset(kop_npf_candidates()[$ein])) throw new RuntimeException('That organization is not in the list any more.');
    $decisions = kop_npf_decisions();
    if (($decisions[$ein]['decision'] ?? '') === 'linked') throw new RuntimeException('It is linked: undo it first.');
    $decisions[$ein] = array('decision' => 'rejected', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    kop_npf_save_decisions($decisions);
    return 'Left off. No page changed.';
}

/** Put back what Link did: deletes exactly the link rows it made. Returns the message. */
function kop_npf_undo($ein) {
    $ein = (string) $ein;
    $decisions = kop_npf_decisions();
    $d = $decisions[$ein] ?? null;
    if (!$d) throw new RuntimeException('Nothing to undo.');
    $removed = 0;
    if (($d['decision'] ?? '') === 'linked' && kop_npf_ready()) {
        global $wpdb;
        foreach ((array) ($d['links'] ?? array()) as $lid) {
            foreach (kop_npf_links(array('id' => (int) $lid)) as $l) {
                $kind = (int) $l['operator_id'] ? 'operator' : 'facility';
                $removed += (int) $wpdb->delete(kop_npf_table(), array('id' => (int) $lid));
                kop_npf_purge($kind, $kind === 'operator' ? $l['operator_id'] : $l['facility_id']);
            }
        }
    }
    unset($decisions[$ein]);
    kop_npf_save_decisions($decisions);
    return ($removed ? 'Undone: ' . $removed . ' link' . ($removed === 1 ? '' : 's') . ' taken off. ' : 'Undone. ') . 'It is waiting for review again.';
}

/* ---- Pages --------------------------------------------------------------- */

/**
 * The organizations linked to a facility or company, ready to print:
 * [{ein, ein_label, name, place, kind, since, url, rows: [filing...], years}].
 * An organization whose file is gone (the build dropped it) is left out.
 */
function kop_npf_for($kind, $id) {
    $col = $kind === 'operator' ? 'operator_id' : 'facility_id';
    $out = array();
    $seen = array();
    foreach (kop_npf_links(array($col => (int) $id)) as $l) {
        if (isset($seen[$l['ein']])) continue;
        $seen[$l['ein']] = true;
        $org = kop_npf_org($l['ein']);
        if (!$org) continue;
        $rows = array_values(array_filter((array) ($org['filings'] ?? array()), function ($f) { return !empty($f['year']); }));
        usort($rows, function ($a, $b) { return (int) $b['year'] <=> (int) $a['year']; });
        $years = array_column($rows, 'year');
        $out[] = array(
            'ein'       => (string) $org['ein'],
            'ein_label' => kop_npf_ein_label($org['ein']),
            'name'      => (string) $org['name'],
            'place'     => trim(implode(', ', array_filter(array((string) ($org['city'] ?? ''), (string) ($org['state'] ?? ''))))),
            'kind'      => kop_npf_ntee_label($org['ntee'] ?? ''),
            'since'     => substr((string) ($org['ruling'] ?? ''), 0, 4),
            'url'       => (string) ($org['url'] ?? ''),
            'rows'      => $rows,
            'years'     => $years ? (min($years) === max($years) ? (string) min($years) : min($years) . ' to ' . max($years)) : '',
        );
    }
    return $out;
}

function kop_npf_for_facility($facility_id) {
    return kop_npf_for('facility', $facility_id);
}

function kop_npf_for_operator($operator_id) {
    return kop_npf_for('operator', $operator_id);
}

/** The section body both page templates print: one block per organization. */
function kop_npf_section_html(array $orgs) {
    ob_start();
    foreach ($orgs as $o) :
        $recent = array_slice($o['rows'], 0, 10);
        $earlier = array_slice($o['rows'], 10);
        ?>
        <div class="kop-npf-org">
            <h3 class="kop-fp-subhead"><?php echo esc_html($o['name']); ?></h3>
            <p class="kop-fp-count">
                EIN <?php echo esc_html($o['ein_label']); ?><?php if ($o['place'] !== '') : ?>, <?php echo esc_html($o['place']); ?><?php endif; ?>.
                <?php if ($o['kind'] !== '') : ?>Filed with the IRS as <?php echo esc_html(strtolower($o['kind'])); ?><?php if ($o['since'] !== '') : ?>, tax-exempt since <?php echo esc_html($o['since']); ?><?php endif; ?>.<?php elseif ($o['since'] !== '') : ?>Tax-exempt since <?php echo esc_html($o['since']); ?>.<?php endif; ?>
                <?php if ($o['url'] !== '') : ?><a href="<?php echo esc_url($o['url']); ?>" target="_blank" rel="noopener">Every filing on ProPublica's Nonprofit Explorer</a><?php endif; ?>
            </p>
            <?php if ($o['rows']) : ?>
                <?php echo kop_npf_table_html($recent); ?>
                <?php if ($earlier) : ?>
                    <details class="kop-npf-earlier">
                        <summary>Earlier years (<?php echo count($earlier); ?>)</summary>
                        <?php echo kop_npf_table_html($earlier); ?>
                    </details>
                <?php endif; ?>
            <?php else : ?>
                <p>ProPublica has no returns on file for it.</p>
            <?php endif; ?>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

function kop_npf_table_html(array $rows) {
    ob_start(); ?>
    <div class="kop-npf-scroll">
        <table class="kop-npf-table">
            <thead><tr><th scope="col">Year</th><th scope="col">Revenue</th><th scope="col">Expenses</th><th scope="col">Paid to officers and directors</th><th scope="col">Assets at year end</th><th scope="col">Return</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $f) : ?>
                <tr>
                    <th scope="row"><?php echo (int) $f['year']; ?></th>
                    <td><?php echo esc_html(kop_npf_money($f['revenue'] ?? null)); ?></td>
                    <td><?php echo esc_html(kop_npf_money($f['expenses'] ?? null)); ?></td>
                    <td><?php echo esc_html(kop_npf_money($f['officer_pay'] ?? null)); ?></td>
                    <td><?php echo esc_html(kop_npf_money($f['assets'] ?? null)); ?></td>
                    <td><?php if (!empty($f['pdf'])) : ?><a href="<?php echo esc_url($f['pdf']); ?>" target="_blank" rel="noopener">Form <?php echo esc_html((string) $f['form']); ?></a><?php else : ?>Form <?php echo esc_html((string) $f['form']); ?><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
