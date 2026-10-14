<?php
/**
 * State business filings (corporations, LLCs, partnerships) on facility and
 * company pages: who registered the company that runs a program, its officers
 * and registered agent, when it was formed, renamed, merged or dissolved, and
 * the trade names it registered.
 *
 * One folder per state in js/data/business-filings/ (kop-data), built by a
 * kop-pipeline script per state (Florida: scripts/florida-sunbiz.py, from the
 * Division of Corporations' quarterly bulk files; Colorado: scripts/colorado-sos.py,
 * from the Secretary of State's open data), sharing scripts/lib/business_filings.py:
 *
 *   <st>/candidates.json      {built, state, source, entities: [{doc, name, type_label, status, filed,
 *                              city, fei, officers [text], agent, trade_names (count), last_report,
 *                              match (matched | check), flags, candidates: [{kind (facility | operator),
 *                              id, how, trade_name, name, place, status, same_town}]}]}
 *   <st>/entities/<doc>.json  {state, doc, name, type, type_label, status, filed, origin, fei, address,
 *                              city, last_activity, status_label? (the state's own word: "Voluntarily
 *                              Dissolved"), reports [{year, filed}], agent, officers [{name,
 *                              kind, title, place | address}], more_officers, events [{date, filed,
 *                              what, name?, other_doc?, notes?, file? (the state's filing id)}], trade_names [{name, county, status,
 *                              filed, expires, cancelled, doc}], url, source}
 *
 * People (officers, agents) carry name, title, city and state only, never a
 * street address. The owner confirms each company at Review inbox > Business
 * filings (inc/review-inbox/business-filings.php): Link ties it to a facility
 * record or a company. A link is a row of {prefix}kop_business_links; the
 * pages read the company's file at render time. Decisions: option
 * kop_business_review, "<st>:<doc>" => {decision (linked | rejected), by, at,
 * links: [link ids]}; Undo deletes exactly those rows.
 *
 * Filed documents (Colorado so far): kop-pipeline `colorado-sos.py pdfs` saves
 * the state's PDF of every filing but the periodic reports, with a
 * manifest.json; copied to the server, api/import-business-filings.php files
 * the PDFs of linked companies in a "Business filings" folder of the program's
 * (or company's) library (kop_bizf_import()). Each attachment carries
 * _kop_bizf_filing = "<st>:<doc>:<filing id>", which the page reads to link
 * each filing to our copy. Undoing a link leaves the documents in the library.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_BIZF_DB_VERSION = '1';

/** The states with a folder, code => name. A new state's build adds its folder; add it here too. */
function kop_bizf_states() {
    return array('FL' => 'Florida', 'CO' => 'Colorado');
}

function kop_bizf_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_business_links';
}

function kop_bizf_install($force = false) {
    if (!$force && get_option('kop_bizf_db') === KOP_BIZF_DB_VERSION) return;
    global $wpdb;
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_bizf_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        state CHAR(2) NOT NULL,
        doc VARCHAR(20) NOT NULL,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        operator_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY entity (state, doc),
        KEY facility_id (facility_id),
        KEY operator_id (operator_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_bizf_db', KOP_BIZF_DB_VERSION, false);
}
add_action('admin_init', 'kop_bizf_install');

function kop_bizf_ready() {
    static $ready = null;
    if ($ready === null) {
        global $wpdb;
        $ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', kop_bizf_table())) === kop_bizf_table();
    }
    return $ready;
}

/* ---- The pipeline's files ------------------------------------------------ */

function kop_bizf_dir($state) {
    return get_stylesheet_directory() . '/js/data/business-filings/' . strtolower($state);
}

/** "FL:L13000156149" => [FL, L13000156149], or null. */
function kop_bizf_split_key($key) {
    return preg_match('/^([A-Z]{2}):([A-Z0-9-]{1,20})$/', (string) $key, $m) ? array($m[1], $m[2]) : null;
}

/** Every state's companies, "<ST>:<doc>" => entry (with 'state'). */
function kop_bizf_candidates() {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = array();
    foreach (array_keys(kop_bizf_states()) as $st) {
        $file = kop_bizf_dir($st) . '/candidates.json';
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        foreach ((array) ($data['entities'] ?? array()) as $e) {
            if (!is_array($e) || !preg_match('/^[A-Z0-9-]{1,20}$/', (string) ($e['doc'] ?? ''))) continue;
            $e['state'] = $st;
            $memo[$st . ':' . $e['doc']] = $e;
        }
    }
    return $memo;
}

/** A company's filing (<st>/entities/<doc>.json), or null. */
function kop_bizf_entity($state, $doc) {
    static $memo = array();
    $state = strtoupper((string) $state);
    $doc = (string) $doc;
    if (!isset(kop_bizf_states()[$state]) || !preg_match('/^[A-Z0-9-]{1,20}$/', $doc)) return null;
    $k = $state . ':' . $doc;
    if (!array_key_exists($k, $memo)) {
        $file = kop_bizf_dir($state) . '/entities/' . $doc . '.json';
        $e = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $memo[$k] = is_array($e) && !empty($e['doc']) ? $e : null;
    }
    return $memo[$k];
}

/** "2004-03-15" => "March 15, 2004"; "" stays "". */
function kop_bizf_date($ymd, $month_only = false) {
    $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ymd) ? strtotime($ymd . ' 12:00:00 UTC') : false;
    return $t ? gmdate($month_only ? 'F Y' : 'F j, Y', $t) : '';
}

/* ---- Links --------------------------------------------------------------- */

function kop_bizf_links(array $where) {
    if (!kop_bizf_ready()) return array();
    global $wpdb;
    $sql = array();
    foreach ($where as $col => $v) {
        if (!in_array($col, array('id', 'state', 'doc', 'facility_id', 'operator_id'), true)) return array();
        $sql[] = $wpdb->prepare("{$col} = %s", (string) $v);
    }
    if (!$sql) return array();
    $rows = $wpdb->get_results('SELECT * FROM ' . kop_bizf_table() . ' WHERE ' . implode(' AND ', $sql) . ' ORDER BY id DESC', ARRAY_A);
    return is_array($rows) ? $rows : array();
}

function kop_bizf_purge($kind, $id) {
    $url = $kind === 'operator'
        ? (function_exists('kop_operator_page_url') ? (string) kop_operator_page_url((int) $id) : '')
        : (function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $id) : '');
    if ($url !== '' && function_exists('do_action')) do_action('litespeed_purge_url', $url);
}

/** Add a link. Returns the new row id, or 0 when the record already has this company. */
function kop_bizf_add_link($state, $doc, $kind, $id, $reviewer) {
    global $wpdb;
    $col = $kind === 'operator' ? 'operator_id' : 'facility_id';
    foreach (kop_bizf_links(array('state' => $state, 'doc' => $doc)) as $l) {
        if ((int) $l[$col] === (int) $id) return 0;
    }
    $wpdb->insert(kop_bizf_table(), array(
        'state' => $state, 'doc' => $doc,
        'facility_id' => $kind === 'operator' ? 0 : (int) $id, 'operator_id' => $kind === 'operator' ? (int) $id : 0,
        'created_by' => substr((string) $reviewer, 0, 60), 'created_at' => gmdate('Y-m-d H:i:s'),
    ));
    kop_bizf_purge($kind, $id);
    return (int) $wpdb->insert_id;
}

/* ---- Review decisions ----------------------------------------------------- */

function kop_bizf_decisions() {
    $saved = get_option('kop_business_review', array());
    return is_array($saved) ? $saved : array();
}

function kop_bizf_save_decisions(array $d) {
    update_option('kop_business_review', $d, false);
}

/** Link company $key ("FL:<doc>") to a facility ($kind 'facility') or company ('operator'). Returns the message. */
function kop_bizf_apply($key, $kind, $id, $reviewer) {
    $key = (string) $key;
    $kind = $kind === 'operator' ? 'operator' : 'facility';
    $id = (int) $id;
    $parts = kop_bizf_split_key($key);
    if (!$parts || !isset(kop_bizf_candidates()[$key])) throw new RuntimeException('That company is not in the list any more.');
    if (!kop_bizf_ready()) throw new RuntimeException('The links table is not set up yet: open any admin page once and try again.');
    if ($id <= 0) throw new RuntimeException($kind === 'operator' ? 'Pick the company.' : 'Pick the record.');
    if (!function_exists('kop_npf_record_exists') || !kop_npf_record_exists($kind, $id)) throw new RuntimeException(($kind === 'operator' ? 'Company' : 'Record') . ' #' . $id . ' is not on file.');
    $decisions = kop_bizf_decisions();
    $d = $decisions[$key] ?? array();
    if (($d['decision'] ?? '') === 'rejected') $d = array();
    $link = kop_bizf_add_link($parts[0], $parts[1], $kind, $id, $reviewer);
    $name = kop_npf_record_name($kind, $id);
    if (!$link) return $name . ' already has this company; nothing changed.';
    $decisions[$key] = array('decision' => 'linked', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'),
        'links' => array_merge((array) ($d['links'] ?? array()), array($link)));
    kop_bizf_save_decisions($decisions);
    return 'Linked to ' . $name . '. Its ' . ($kind === 'operator' ? 'company' : 'facility') . ' page shows the filing now; Undo is on the Linked tab.';
}

function kop_bizf_reject($key, $reviewer) {
    $key = (string) $key;
    if (!isset(kop_bizf_candidates()[$key])) throw new RuntimeException('That company is not in the list any more.');
    $decisions = kop_bizf_decisions();
    if (($decisions[$key]['decision'] ?? '') === 'linked') throw new RuntimeException('It is linked: undo it first.');
    $decisions[$key] = array('decision' => 'rejected', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    kop_bizf_save_decisions($decisions);
    return 'Left off. No page changed.';
}

/** Put back what Link did: deletes exactly the link rows it made. */
function kop_bizf_undo($key) {
    $key = (string) $key;
    $decisions = kop_bizf_decisions();
    $d = $decisions[$key] ?? null;
    if (!$d) throw new RuntimeException('Nothing to undo.');
    $removed = 0;
    if (($d['decision'] ?? '') === 'linked' && kop_bizf_ready()) {
        global $wpdb;
        foreach ((array) ($d['links'] ?? array()) as $lid) {
            foreach (kop_bizf_links(array('id' => (int) $lid)) as $l) {
                $kind = (int) $l['operator_id'] ? 'operator' : 'facility';
                $removed += (int) $wpdb->delete(kop_bizf_table(), array('id' => (int) $lid));
                kop_bizf_purge($kind, $kind === 'operator' ? $l['operator_id'] : $l['facility_id']);
            }
        }
    }
    unset($decisions[$key]);
    kop_bizf_save_decisions($decisions);
    return ($removed ? 'Undone: ' . $removed . ' link' . ($removed === 1 ? '' : 's') . ' taken off. ' : 'Undone. ') . 'It is waiting for review again.';
}

/* ---- Pages --------------------------------------------------------------- */

/** The companies linked to a facility or company, as their filings (newest link first, each once). */
function kop_bizf_for($kind, $id) {
    $col = $kind === 'operator' ? 'operator_id' : 'facility_id';
    $out = array();
    $seen = array();
    foreach (kop_bizf_links(array($col => (int) $id)) as $l) {
        $k = $l['state'] . ':' . $l['doc'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $e = kop_bizf_entity($l['state'], $l['doc']);
        if ($e) $out[] = $e;
    }
    return $out;
}

function kop_bizf_for_facility($facility_id) {
    return kop_bizf_for('facility', $facility_id);
}

function kop_bizf_for_operator($operator_id) {
    return kop_bizf_for('operator', $operator_id);
}

/** The section body both page templates print: one block per company. */
function kop_bizf_section_html(array $entities) {
    $states = kop_bizf_states();
    ob_start();
    foreach ($entities as $e) :
        $state_name = $states[$e['state']] ?? $e['state'];
        $filed = kop_bizf_date($e['filed'] ?? '');
        $last = $e['reports'][0] ?? null;
        $line = array_filter(array(
            (string) ($e['type_label'] ?? ''),
            'document ' . $e['doc'],
            $filed !== '' ? 'filed ' . $filed : '',
            (!empty($e['status_label']) ? strtolower($e['status_label']) : (($e['status'] ?? '') === 'active' ? 'active' : 'inactive'))
                . (($e['status'] ?? '') === 'active' && $last ? ' (last annual report ' . (int) $last['year'] . ')' : ''),
        ));
        $trade = (array) ($e['trade_names'] ?? array());
        $copies = kop_bizf_copies($e['state'], $e['doc']);
        ?>
        <div class="kop-bizf-org">
            <h3 class="kop-fp-subhead"><?php echo esc_html($e['name']); ?></h3>
            <p class="kop-fp-count"><?php echo esc_html(ucfirst(implode(', ', $line))); ?>.
                <?php if (!empty($e['fei'])) : ?>Federal employer number <?php echo esc_html(substr($e['fei'], 0, 2) . '-' . substr($e['fei'], 2)); ?>.<?php endif; ?>
                <?php if (!empty($e['url'])) : ?><a href="<?php echo esc_url($e['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($state_name); ?>'s record of it</a><?php endif; ?>
            </p>
            <dl class="kop-bizf-facts">
                <?php if (!empty($e['address'])) : ?>
                    <dt>Principal address</dt><dd><?php echo esc_html($e['address']); ?></dd>
                <?php endif; ?>
                <?php if (!empty($e['officers'])) : ?>
                    <dt>Officers on file</dt>
                    <dd><ul class="kop-bizf-people">
                        <?php foreach ($e['officers'] as $o) : ?>
                            <li><?php echo esc_html($o['name']); ?><?php if ($o['title'] !== '') : ?>, <?php echo esc_html($o['title']); ?><?php endif; ?><?php
                                $where = $o['kind'] === 'company' ? ($o['address'] ?? '') : ($o['place'] ?? '');
                                if ($where !== '') : ?> <span class="meta">(<?php echo esc_html($where); ?>)</span><?php endif; ?></li>
                        <?php endforeach; ?>
                        <?php if (!empty($e['more_officers'])) : ?><li class="meta">More officers are listed on the state's record.</li><?php endif; ?>
                    </ul></dd>
                <?php endif; ?>
                <?php if (!empty($e['agent'])) : $a = $e['agent']; ?>
                    <dt>Registered agent</dt>
                    <dd><?php echo esc_html($a['name']); ?><?php $where = $a['kind'] === 'company' ? ($a['address'] ?? '') : ($a['place'] ?? '');
                        if ($where !== '') : ?> <span class="meta">(<?php echo esc_html($where); ?>)</span><?php endif; ?></dd>
                <?php endif; ?>
            </dl>
            <?php if (!empty($e['events'])) : ?>
                <h4 class="kop-bizf-h">Filings since it was formed</h4>
                <ul class="kop-fp-notes">
                    <?php foreach ($e['events'] as $ev) : ?>
                        <li><?php $d = kop_bizf_date($ev['date'] ?? ''); if ($d !== '') : ?><strong><?php echo esc_html($d); ?>:</strong> <?php endif; ?><?php
                            $copy = !empty($ev['file']) ? ($copies[(string) $ev['file']] ?? '') : '';
                            if ($copy !== '') : ?><a href="<?php echo esc_url($copy); ?>"><?php echo esc_html($ev['what']); ?></a><?php else : echo esc_html($ev['what']); endif; ?><?php
                            if (!empty($ev['name'])) : ?>, as <?php echo esc_html($ev['name']); ?><?php endif; ?><?php
                            if (!empty($ev['notes'])) : ?> <span class="meta">(<?php echo esc_html($ev['notes']); ?>)</span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($trade) : ?>
                <h4 class="kop-bizf-h">Trade names it registered</h4>
                <ul class="kop-fp-notes">
                    <?php foreach (array_slice($trade, 0, 12) as $t) echo kop_bizf_trade_li($t); ?>
                </ul>
                <?php if (count($trade) > 12) : ?>
                    <details class="kop-npf-earlier">
                        <summary>More trade names (<?php echo count($trade) - 12; ?>)</summary>
                        <ul class="kop-fp-notes"><?php foreach (array_slice($trade, 12) as $t) echo kop_bizf_trade_li($t); ?></ul>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

function kop_bizf_trade_li(array $t) {
    $bits = array_filter(array(
        !empty($t['county']) ? (strcasecmp($t['county'], 'Multiple') === 0 ? 'several counties' : $t['county'] . ' County') : '',
        !empty($t['filed']) ? 'registered ' . kop_bizf_date($t['filed'], true) : '',
        ($t['status'] ?? '') === 'active' ? 'active' : (!empty($t['cancelled']) ? 'cancelled ' . kop_bizf_date($t['cancelled'], true) : (string) ($t['status'] ?? '')),
    ));
    return '<li>' . esc_html($t['name']) . ($bits ? ' <span class="meta">(' . esc_html(implode(', ', $bits)) . ')</span>' : '') . '</li>';
}

/* ---- Filed documents ------------------------------------------------------ */

/** Our copies of a company's filings: filing id => attachment URL. */
function kop_bizf_copies($state, $doc) {
    global $wpdb;
    if (!isset($wpdb->postmeta)) return array();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_kop_bizf_filing' AND meta_value LIKE %s",
        $wpdb->esc_like($state . ':' . $doc . ':') . '%'
    ), ARRAY_A);
    $out = array();
    foreach ((array) $rows as $r) {
        $parts = explode(':', (string) $r['meta_value']);
        $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url((int) $r['post_id']) : '';
        if (count($parts) === 3 && $url !== '') $out[$parts[2]] = $url;
    }
    return $out;
}

/** The linked record's library folder: a facility's (kop_wb_facility_folder()), a company's document_folder_id. 0 = none, -1 = made when applied. */
function kop_bizf_record_folder($kind, $id, $apply) {
    global $wpdb;
    if ($kind === 'operator') {
        return (int) $wpdb->get_var($wpdb->prepare('SELECT document_folder_id FROM ' . $wpdb->prefix . 'kop_operators WHERE id = %d', (int) $id));
    }
    if (!$apply) {
        if (function_exists('kop_facility_pages_index')) {
            $index = kop_facility_pages_index();
            if (!empty($index['ids'][(int) $id]['folder'])) return (int) $index['ids'][(int) $id]['folder'];
        }
        return -1;
    }
    return function_exists('kop_wb_facility_folder') ? (int) kop_wb_facility_folder((int) $id) : 0;
}

/**
 * File the PDFs of every linked company found in $dir/manifest.json
 * ({"<doc>/<filing>": {state, entity, filing, what, date, url, file}}) in a
 * "Business filings" folder of each linked record's library. Safe to run
 * again: a filing already imported is only filed where it is missing.
 *
 * @return array report lines
 */
function kop_bizf_import($dir, $apply) {
    global $wpdb;
    $manifest = json_decode((string) @file_get_contents($dir . '/manifest.json'), true);
    if (!is_array($manifest)) throw new RuntimeException('No manifest.json in ' . $dir);
    $by_doc = array();
    foreach ($manifest as $item) {
        if (empty($item['file']) || empty($item['entity'])) continue;
        $by_doc[strtoupper($item['state'] ?? 'CO') . ':' . $item['entity']][] = $item;
    }
    $rel = $wpdb->prefix . 'fbv_attachment_folder';
    $states = kop_bizf_states();
    $report = array();
    $folders = array();
    $rows = $wpdb->get_results('SELECT * FROM ' . kop_bizf_table() . ' ORDER BY id', ARRAY_A);
    foreach ((array) $rows as $l) {
        $key = $l['state'] . ':' . $l['doc'];
        if (empty($by_doc[$key])) continue;
        $kind = (int) $l['operator_id'] ? 'operator' : 'facility';
        $rid = $kind === 'operator' ? (int) $l['operator_id'] : (int) $l['facility_id'];
        $line = array('company' => $key, 'record' => $kind . ' #' . $rid, 'filings' => count($by_doc[$key]));
        try {
            $e = kop_bizf_entity($l['state'], $l['doc']);
            $company = $e ? (string) $e['name'] : $l['doc'];
            $state_name = $states[$l['state']] ?? $l['state'];
            $parent = kop_bizf_record_folder($kind, $rid, $apply);
            if ($parent === 0) throw new RuntimeException('the company has no document folder; link the filing to one of its programs instead');
            $folder = -1;
            if ($parent > 0) {
                $folder = $folders[$parent] ?? kop_wb_find_folder('Business filings', $parent);
                if (!$folder) $folder = $apply ? kop_wb_create_folder('Business filings', $parent) : -1;
                if ($folder > 0) $folders[$parent] = $folder;
            }
            $line['folder'] = $folder > 0 ? '#' . $folder : 'would be made';
            $added = 0;
            $filed = 0;
            $typed = array();
            foreach ($by_doc[$key] as $item) {
                $path = $dir . '/' . $item['file'];
                if (!is_file($path)) continue;
                $tag = $l['state'] . ':' . $l['doc'] . ':' . $item['filing'];
                $att = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kop_bizf_filing' AND meta_value = %s LIMIT 1", $tag));
                $md5 = md5_file($path);
                if (!$att) {
                    $att = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'attachment'
                         WHERE m.meta_key IN ('_kop_import_md5', 'mdd_hash') AND m.meta_value = %s ORDER BY m.post_id LIMIT 1", $md5));
                    if ($att && $apply) update_post_meta($att, '_kop_bizf_filing', $tag);
                }
                if (!$att) {
                    $added++;
                    if (!$apply) continue;
                    $upload = wp_upload_bits(sanitize_file_name($l['state'] . '-' . $l['doc'] . '-' . $item['filing'] . '.pdf'), null, (string) file_get_contents($path));
                    if (!empty($upload['error'])) throw new RuntimeException('upload failed: ' . $upload['error']);
                    $date = kop_bizf_date($item['date'] ?? '');
                    $att = wp_insert_attachment(array(
                        'post_mime_type' => 'application/pdf',
                        'post_title'     => $company . ': ' . $item['what'] . ($date !== '' ? ', ' . $date : '') . ' (' . $state_name . ' business filing)',
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                    ), $upload['file']);
                    if (is_wp_error($att) || !$att) {
                        @unlink($upload['file']);
                        throw new RuntimeException('could not register the attachment');
                    }
                    wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $upload['file']));
                    update_post_meta($att, '_kop_import_md5', $md5);
                    update_post_meta($att, '_kop_source_url', esc_url_raw((string) $item['url']));
                    update_post_meta($att, '_kop_bizf_filing', $tag);
                    $typed[] = (int) $att;
                }
                if ($apply && $folder > 0 && !(int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$rel} WHERE attachment_id = %d AND folder_id = %d", $att, $folder))) {
                    $wpdb->insert($rel, array('folder_id' => $folder, 'attachment_id' => $att), array('%d', '%d'));
                    $filed++;
                }
            }
            if ($typed && function_exists('kop_dt_change')) {
                kop_dt_change(array_fill_keys($typed, array('business-record')), 'rule', 'State business filings imported', 'import');
            }
            $line[$apply ? 'imported' : 'would import'] = $added;
            if ($apply) {
                $line['filed'] = $filed;
                kop_bizf_purge($kind, $rid);
            }
        } catch (Throwable $ex) {
            $line['error'] = $ex->getMessage();
        }
        $report[] = $line;
    }
    if ($apply) delete_transient('kop_hidden_preview_ids');
    return $report;
}
