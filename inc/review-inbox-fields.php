<?php
/**
 * KOP Tools > Card Fields: what each review inbox queue's cards show, and what
 * kinds of record its pickers offer.
 *
 * Fields: every editable field (by name) and every detail line (by label) a
 * queue's cards have had is remembered as cards are drawn
 * (kop_review_card_fields_seen). The owner unticks the ones a queue's cards
 * should not show. A hidden detail line is left off the card; a hidden
 * editable field stays in the editor as an invisible input that still sends
 * its value, so a queue's save never sees a field go missing.
 *
 * Record pickers: a queue whose actions can file an item on any kind of
 * record says so with 'record_kinds' => array(kinds) in its source spec
 * (kop_rinbox_register()); its 'facility' pickers (fields, action params,
 * moves) are then drawn as the any-record finder (type 'record',
 * inc/record-field.php), offering the kinds the owner ticked for that queue
 * (default: all the queue takes). Its act/save gets "12" for a program or
 * "<kind>:<id>" for anything else (kop_record_field_parse()). A param can opt
 * out with 'facility_only' => true (homes, merges: programs by nature).
 *
 * Option kop_review_card_fields = {queue: {hide: [field names], hide_details:
 * [labels], kinds: [kinds]}}, only what differs from the default; the
 * previous value is kept for Undo.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Every kind a picker can offer, as the screen names them. */
function kop_rinbox_record_kinds() {
    return array(
        'facility'    => 'Program (facility)',
        'company'     => 'Company',
        'consultant'  => 'Educational consultant',
        'transporter' => 'Transport company',
        'provider'    => 'Mental health provider',
        'marketer'    => 'Marketing company',
        'tradeorg'    => 'Trade organization',
        'ya'          => 'Young adult program (18+)',
    );
}

function kop_rinbox_card_fields_all() {
    $v = get_option('kop_review_card_fields', array());
    return is_array($v) ? $v : array();
}

function kop_rinbox_card_fields_for($source) {
    $all = kop_rinbox_card_fields_all();
    return is_array($all[$source] ?? null) ? $all[$source] : array();
}

/** The kinds a queue takes (its spec's 'record_kinds'; empty = programs only). */
function kop_rinbox_source_record_kinds($source) {
    $src = kop_rinbox_sources()[$source] ?? array();
    $k = $src['record_kinds'] ?? array();
    if ($k === true || $k === 'all') return array_keys(kop_rinbox_record_kinds());
    return array_values(array_intersect(array_keys(kop_rinbox_record_kinds()), (array) $k));
}

/** The kinds the queue's pickers offer now: the owner's choice within what it takes. */
function kop_rinbox_card_kinds($source) {
    $takes = kop_rinbox_source_record_kinds($source);
    if (!$takes) return array();
    $set = kop_rinbox_card_fields_for($source)['kinds'] ?? null;
    if (!is_array($set)) return $takes;
    $out = array_values(array_intersect($takes, $set));
    return $out ?: array('facility');
}

/** Remember the fields and detail labels a queue's cards have (for the screen). */
function kop_rinbox_card_fields_note(array $seen_now) {
    if (!$seen_now) return;
    $seen = get_option('kop_review_card_fields_seen', array());
    $seen = is_array($seen) ? $seen : array();
    $changed = false;
    foreach ($seen_now as $source => $kinds) {
        foreach ($kinds as $kind => $names) {
            foreach ($names as $name => $label) {
                if (!isset($seen[$source][$kind][$name]) || $seen[$source][$kind][$name] !== $label) {
                    $seen[$source][$kind][$name] = $label;
                    $changed = true;
                }
            }
        }
    }
    if ($changed) update_option('kop_review_card_fields_seen', $seen, false);
}

/** A picker param or field as the any-record finder, offering $kinds. */
function kop_rinbox_card_record_param(array $f, array $kinds) {
    if (!empty($f['facility_only']) || !in_array($f['type'] ?? '', array('facility', 'record'), true)) return $f;
    if (($f['type'] ?? '') === 'record' && !empty($f['kinds'])) {
        // A picker that already names its kinds keeps only those the owner allows.
        $keep = array_values(array_intersect(array_map('kop_record_field_kind', (array) $f['kinds']), $kinds));
        if ($keep) $f['kinds'] = $keep;
        return $f;
    }
    $f['type'] = 'record';
    $f['kinds'] = $kinds;
    $f['create'] = array();
    $v = (string) ($f['value'] ?? '');
    if ($v !== '' && $v !== '0' && empty($f['value_label']) && function_exists('kop_record_field_label')) {
        $f['value_label'] = kop_record_field_label($v);
    }
    if ($v === '0') $f['value'] = '';
    return $f;
}

/** One card with the owner's choices applied; $seen collects what it has. */
function kop_rinbox_card_fields_apply($source, array $it, array &$seen) {
    $cfg = kop_rinbox_card_fields_for($source);
    $hide = array_flip((array) ($cfg['hide'] ?? array()));
    $hide_details = array_flip((array) ($cfg['hide_details'] ?? array()));
    $kinds = kop_rinbox_card_kinds($source);

    foreach ($it['fields'] as $i => $f) {
        if (!is_array($f) || !isset($f['name'])) continue;
        $seen[$source]['fields'][(string) $f['name']] = (string) ($f['label'] ?? $f['name']);
        if (isset($hide[(string) $f['name']])) $it['fields'][$i]['hidden_by_owner'] = true;
        if ($kinds) $it['fields'][$i] = kop_rinbox_card_record_param($it['fields'][$i], $kinds);
    }
    if (is_array($it['details'])) {
        $keep = array();
        foreach ($it['details'] as $d) {
            $label = is_array($d) ? (string) ($d['label'] ?? '') : '';
            if ($label !== '') $seen[$source]['details'][$label] = $label;
            if ($label !== '' && isset($hide_details[$label])) continue;
            $keep[] = $d;
        }
        $it['details'] = $keep;
    }
    // A queue whose pickers find programs only: its actions' pickers find young adult programs too where the
    // card can be filed on one, and picking one files it there (kop_rinbox_ya_file_picked()).
    $act_kinds = $kinds ?: (function_exists('kop_rinbox_ya_picker_ok') && kop_rinbox_ya_picker_ok($source, $it) ? array('facility', 'ya') : array());
    foreach ($act_kinds ? array('actions', 'moves') : array() as $list) {
        if ($list === 'moves' && !$kinds) continue;
        foreach ((array) $it[$list] as $a => $act) {
            if (!is_array($act) || empty($act['params'])) continue;
            foreach ((array) $act['params'] as $p => $param) {
                if (is_array($param)) $it[$list][$a]['params'][$p] = kop_rinbox_card_record_param($param, $act_kinds);
            }
        }
    }
    return $it;
}

/** Save one queue's choices; keeps the store before it for Undo. */
function kop_rinbox_card_fields_save($source, array $show_fields, array $show_details, $kinds) {
    $before = kop_rinbox_card_fields_all();
    $all = $before;
    $seen = get_option('kop_review_card_fields_seen', array());
    $cfg = array();
    $hide = array_values(array_diff(array_keys((array) ($seen[$source]['fields'] ?? array())), $show_fields));
    $hide_d = array_values(array_diff(array_keys((array) ($seen[$source]['details'] ?? array())), $show_details));
    if ($hide) $cfg['hide'] = $hide;
    if ($hide_d) $cfg['hide_details'] = $hide_d;
    $takes = kop_rinbox_source_record_kinds($source);
    if ($takes && is_array($kinds)) {
        $kinds = array_values(array_intersect($takes, $kinds));
        if ($kinds !== $takes) $cfg['kinds'] = $kinds ?: array('facility');
    }
    if ($cfg) $all[$source] = $cfg;
    else unset($all[$source]);
    update_option('kop_review_card_fields_previous', $before, false);
    update_option('kop_review_card_fields', $all, false);
    return $cfg;
}

function kop_rinbox_card_fields_admin_menu() {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Card Fields', 'Card Fields', 'manage_options', 'kop-card-fields', 'kop_rinbox_card_fields_page');
}
add_action('admin_menu', 'kop_rinbox_card_fields_admin_menu', 30);

function kop_rinbox_card_fields_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $sources = kop_rinbox_sources();
    $source = sanitize_key($_GET['queue'] ?? '');
    $base = admin_url('admin.php?page=kop-card-fields');
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('kop_card_fields');
        if (!empty($_POST['kop_undo'])) {
            $prev = get_option('kop_review_card_fields_previous', null);
            if (is_array($prev)) {
                update_option('kop_review_card_fields_previous', kop_rinbox_card_fields_all(), false);
                update_option('kop_review_card_fields', $prev, false);
                $message = 'Put back as it was before the last save.';
            }
        } elseif ($source !== '' && isset($sources[$source])) {
            $show_f = array_map('strval', array_keys((array) wp_unslash($_POST['show_field'] ?? array())));
            $show_d = array_map('strval', array_keys((array) wp_unslash($_POST['show_detail'] ?? array())));
            $kinds = isset($_POST['kinds_sent']) ? array_map('sanitize_key', array_keys((array) ($_POST['kind'] ?? array()))) : null;
            kop_rinbox_card_fields_save($source, $show_f, $show_d, $kinds);
            $message = 'Saved. The queue\'s cards use it the next time they load.';
        }
    }

    $seen = get_option('kop_review_card_fields_seen', array());
    $groups = array();
    foreach ($sources as $key => $s) $groups[(string) ($s['group'] ?? 'Other')][$key] = (string) ($s['label'] ?? $key);
    ksort($groups);

    echo '<div class="wrap kop-card-fields"><h1>Card Fields</h1>';
    echo '<style>.kop-card-fields .kcf-cols{display:grid;grid-template-columns:minmax(0,260px) minmax(0,1fr);gap:24px;align-items:start}'
        . '@media (max-width:782px){.kop-card-fields .kcf-cols{grid-template-columns:minmax(0,1fr)}.kop-card-fields .kcf-main{order:-1}}'
        . '.kop-card-fields nav{background:#fff;border:1px solid #c3c4c7;padding:8px 12px}.kop-card-fields nav h3{margin:12px 0 4px;font-size:12px;text-transform:uppercase;color:#4A5568}'
        . '.kop-card-fields nav a{display:block;padding:3px 0;text-decoration:none}.kop-card-fields nav a.current{font-weight:600;color:#000435}'
        . '.kop-card-fields fieldset{background:#fff;border:1px solid #c3c4c7;padding:8px 14px;margin:0 0 16px}.kop-card-fields legend{font-weight:600;padding:0 4px}'
        . '.kop-card-fields label.kcf-box{display:inline-flex;gap:6px;align-items:center;min-width:220px;margin:4px 16px 4px 0}.kop-card-fields .kcf-name{font-family:monospace;font-size:11px;color:#4A5568}</style>';
    echo '<p style="max-width:760px">Pick a review queue to choose what its cards show and, where a card files something on a record, '
        . 'what kinds of record its picker offers (a program, a company, an educational consultant, a transport company, a young adult program...).</p>';
    if ($message !== '') echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';

    echo '<div class="kcf-cols"><nav aria-label="Queues">';
    foreach ($groups as $g => $list) {
        asort($list);
        echo '<h3>' . esc_html($g) . '</h3>';
        foreach ($list as $key => $label) {
            echo '<a href="' . esc_url(add_query_arg('queue', $key, $base)) . '"' . ($key === $source ? ' class="current" aria-current="page"' : '') . '>'
                . esc_html($label) . (kop_rinbox_source_record_kinds($key) ? ' <span class="kcf-name">(any record)</span>' : '') . '</a>';
        }
    }
    echo '</nav><div class="kcf-main">';
    if ($source === '' || !isset($sources[$source])) {
        echo '<p>Pick a queue on the left.</p>';
    } else {
        $cfg = kop_rinbox_card_fields_for($source);
        $hide = array_flip((array) ($cfg['hide'] ?? array()));
        $hide_d = array_flip((array) ($cfg['hide_details'] ?? array()));
        echo '<h2>' . esc_html($sources[$source]['label'] ?? $source) . '</h2>';
        echo '<form method="post" action="' . esc_url(add_query_arg('queue', $source, $base)) . '">';
        wp_nonce_field('kop_card_fields');

        $takes = kop_rinbox_source_record_kinds($source);
        echo '<fieldset><legend>Records its picker offers</legend>';
        if ($takes) {
            echo '<input type="hidden" name="kinds_sent" value="1">';
            $on = array_flip(kop_rinbox_card_kinds($source));
            foreach (kop_rinbox_record_kinds() as $k => $label) {
                if (!in_array($k, $takes, true)) continue;
                echo '<label class="kcf-box"><input type="checkbox" name="kind[' . esc_attr($k) . ']" value="1"' . (isset($on[$k]) ? ' checked' : '') . '> ' . esc_html($label) . '</label>';
            }
            echo '<p class="description">A program gets the item as this queue files it on a facility page. A company gets links in its '
                . '"Materials and links" and news on its page; any other record gets a line in its notes citing the item\'s link. Undo works for each.</p>';
        } else {
            echo '<p>This queue files items on programs only (what it does has no place on another kind of record), or has no picker.</p>';
        }
        echo '</fieldset>';

        $fields = (array) ($seen[$source]['fields'] ?? array());
        echo '<fieldset><legend>Fields in "Edit details"</legend>';
        if ($fields) {
            foreach ($fields as $name => $label) {
                echo '<label class="kcf-box"><input type="checkbox" name="show_field[' . esc_attr($name) . ']" value="1"' . (isset($hide[$name]) ? '' : ' checked') . '> '
                    . esc_html($label) . ' <span class="kcf-name">' . esc_html($name) . '</span></label>';
            }
            echo '<p class="description">An unticked field is not shown. Its value stays as it is on the item.</p>';
        } else {
            echo '<p>None seen yet. Open this queue in the Review inbox once and its fields are listed here.</p>';
        }
        echo '</fieldset>';

        $details = (array) ($seen[$source]['details'] ?? array());
        echo '<fieldset><legend>Lines shown on the card</legend>';
        if ($details) {
            foreach ($details as $label) {
                echo '<label class="kcf-box"><input type="checkbox" name="show_detail[' . esc_attr($label) . ']" value="1"' . (isset($hide_d[$label]) ? '' : ' checked') . '> ' . esc_html($label) . '</label>';
            }
        } else {
            echo '<p>None seen yet. Open this queue in the Review inbox once and its lines are listed here.</p>';
        }
        echo '</fieldset>';
        echo '<p><button type="submit" class="button button-primary">Save this queue</button></p></form>';
    }
    if (is_array(get_option('kop_review_card_fields_previous', null))) {
        echo '<form method="post" action="' . esc_url($source !== '' ? add_query_arg('queue', $source, $base) : $base) . '">';
        wp_nonce_field('kop_card_fields');
        echo '<p><button type="submit" name="kop_undo" value="1" class="button">Undo the last save</button></p></form>';
    }
    echo '</div></div></div>';
}
