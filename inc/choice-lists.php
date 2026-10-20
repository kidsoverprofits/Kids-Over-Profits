<?php
/**
 * Dropdown lists the owner can add to: KOP Tools > Dropdown Lists.
 *
 * Every choice list on it (website kinds, document types, facility types,
 * article types, lawsuit statuses...) is registered with its built-in
 * choices in inc/choice-lists/<group>.php (kop_choice_register(), 'defaults'),
 * and the list's own function reads it through kop_choices():
 *
 *     function kop_ext_website_kinds($mode = 'all') {
 *         return kop_choices('website-kinds', null, $mode);
 *     }
 *
 * The registry files need no WordPress, so api/ endpoints that run without
 * it and the tests read the same lists (require inc/choice-lists.php).
 * A list may instead keep its defaults inline (kop_choices($key, array(..)))
 * with 'source' naming its function; the screen captures them from it.
 *
 * kop_choices() adds the choices added on the screen, applies renamed labels
 * and the screen's extra fields, and (for $mode 'offered') leaves out hidden
 * ones. 'all' keeps hidden choices, so a record that already uses one still
 * reads and saves; dropdowns, pickers and AI prompts ask for 'offered'.
 * Choices are never deleted: a value may be stored in records.
 *
 * Each list is registered once with kop_choice_register() next to its
 * function (label, where it shows, 'source' = that function, extra 'fields'
 * a new choice needs). The screen reads the built-in choices by calling the
 * source while kop_choices() hands back its defaults untouched.
 *
 * Two shapes:
 *   keyed  value => label (string) or value => array('label' => .., field => ..)
 *          (lists with 'fields'). The value is what records store; a new
 *          choice's value is made from its label (kop_choice_slug()).
 *   plain  a list of strings ('plain' => true), stored as written (facility
 *          types, content warnings, tags). kop_choices() returns a list.
 *
 * Storage: option kop_choice_lists = {list: {items: {value: {label?, hidden?,
 * added?, pos?, <field>?}}}}; each change is logged in kop_choice_lists_log
 * (before/after, for Undo). Every save also writes uploads/kop-choice-lists/
 * lists.json, which the api/ endpoints that run without WordPress read.
 *
 * The browser gets window.KOP_CHOICE_LISTS = {list: [..]} for lists
 * registered with 'js' => true (plain: strings; keyed: {value, label, ...}),
 * offered choices only. A script keeps its own copy as the fallback.
 *
 * scripts/test-choice-lists.php checks merging, saving, Undo and every list.
 */

if (!defined('KOP_CHOICE_LISTS_OPTION')) {
    define('KOP_CHOICE_LISTS_OPTION', 'kop_choice_lists');
}
if (!defined('KOP_CHOICE_LISTS_PAGE')) {
    define('KOP_CHOICE_LISTS_PAGE', 'kop-choice-lists');
}

if (!function_exists('kop_choice_register')) {
    /**
     * Register a list. $def: defaults (the built-in choices: value => label
     * or array, or a list of strings for 'plain'), label, group, where (what the
     * list is used for, shown on the screen), source (the function returning
     * the list through kop_choices()), plain, fields {name: {label, type
     * text|textarea|select|check, options (array or callable), required,
     * help}}, slug_sep ('_' default, or '-'), js, last (values kept at the
     * end, default other/unknown), ordered (keep the built-in order instead
     * of A to Z: a sequence), note (a warning shown over the list),
     * max_len (label length, default 80).
     */
    function kop_choice_register($key, array $def) {
        $GLOBALS['kop_choice_registry'][$key] = $def + array(
            'label' => $key, 'group' => 'Other lists', 'where' => '', 'source' => '', 'plain' => false,
            'fields' => array(), 'slug_sep' => '_', 'js' => false, 'last' => array('other', 'unknown', 'Other'),
            'note' => '', 'max_len' => 80, 'defaults' => null, 'ordered' => false,
        );
    }
}

if (!function_exists('kop_choice_registry')) {
    /** Every registered list, by group then label. */
    function kop_choice_registry() {
        $all = (array) ($GLOBALS['kop_choice_registry'] ?? array());
        uasort($all, function ($a, $b) {
            return strcmp($a['group'], $b['group']) ?: strcasecmp($a['label'], $b['label']);
        });
        return $all;
    }
}

if (!function_exists('kop_choice_def')) {
    function kop_choice_def($key) {
        return $GLOBALS['kop_choice_registry'][$key] ?? null;
    }
}

if (!function_exists('kop_choice_mirror_path')) {
    /** uploads/kop-choice-lists/lists.json (the copy read without WordPress). */
    function kop_choice_mirror_path() {
        if (defined('KOP_CHOICE_LISTS_FILE')) return KOP_CHOICE_LISTS_FILE;
        if (function_exists('wp_upload_dir')) {
            $up = wp_upload_dir(null, false);
            if (!empty($up['basedir'])) return rtrim($up['basedir'], '/\\') . '/kop-choice-lists/lists.json';
        }
        // inc/ -> theme -> themes -> wp-content
        return dirname(__DIR__, 3) . '/uploads/kop-choice-lists/lists.json';
    }
}

if (!function_exists('kop_choice_saved_all')) {
    /** The saved changes for every list. $reset after a save. */
    function kop_choice_saved_all($reset = false) {
        static $memo = null;
        if ($reset) $memo = null;
        if ($memo !== null) return $memo;
        if (isset($GLOBALS['kop_choice_test_store'])) return $memo = (array) $GLOBALS['kop_choice_test_store'];
        if (function_exists('get_option')) {
            $saved = get_option(KOP_CHOICE_LISTS_OPTION, array());
            return $memo = is_array($saved) ? $saved : array();
        }
        $path = kop_choice_mirror_path();
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        return $memo = is_array($data) ? $data : array();
    }
}

if (!function_exists('kop_choices')) {
    /**
     * A list with the screen's changes. $defaults = the built-in choices;
     * $mode 'all' (hidden kept: reading and checking stored values) or
     * 'offered' (what a dropdown, picker or AI prompt offers).
     * Choices come A to Z by label (catch-alls like Other last), so an added
     * one is found where it belongs; a list registered 'ordered' (a sequence,
     * like bill statuses) or $sorted false keeps the built-in order.
     */
    function kop_choices($key, $defaults = null, $mode = 'all', $sorted = true) {
        if ($defaults === null) {
            $d = kop_choice_def($key);
            $defaults = $d && is_array($d['defaults'] ?? null) ? $d['defaults'] : array();
        }
        if (isset($GLOBALS['kop_choice_capture']) && $GLOBALS['kop_choice_capture'] === $key) {
            $GLOBALS['kop_choice_captured'] = $defaults;
            return $defaults;
        }
        $def = kop_choice_def($key);
        $plain = $def ? !empty($def['plain']) : array_values($defaults) === $defaults && !array_filter($defaults, 'is_array');
        $saved = kop_choice_saved_all();
        $items = (array) ($saved[$key]['items'] ?? array());
        $base = array();
        foreach ($defaults as $k => $v) {
            if ($plain) $base[(string) $v] = (string) $v;
            else $base[$k] = $v;
        }
        $out = array();
        $hidden = array();
        foreach ($base as $value => $v) {
            $o = isset($items[$value]) && is_array($items[$value]) ? $items[$value] : array();
            if (!empty($o['hidden'])) $hidden[$value] = true;
            $out[$value] = $plain ? $v : kop_choice_merge_item($v, $o);
        }
        $added = array();
        foreach ($items as $value => $o) {
            if (!is_array($o) || empty($o['added']) || isset($base[$value])) continue;
            $value = (string) $value;
            if (!empty($o['hidden'])) $hidden[$value] = true;
            $added[$value] = (int) ($o['pos'] ?? 0);
        }
        asort($added);
        $is_array_list = !$plain && ($def && !empty($def['fields']) || array_filter($base, 'is_array'));
        foreach (array_keys($added) as $value) {
            $o = $items[$value];
            $label = trim((string) ($o['label'] ?? $value));
            if ($plain) {
                $out[$value] = $value;
            } elseif ($is_array_list) {
                $row = array('label' => $label);
                foreach ((array) ($def['fields'] ?? array()) as $f => $_) {
                    if (array_key_exists($f, $o)) $row[$f] = $o[$f];
                }
                $out[$value] = $row;
            } else {
                $out[$value] = $label;
            }
        }
        if ($sorted && empty($def['ordered'])) {
            uasort($out, function ($a, $b) use ($plain) {
                return strnatcasecmp($plain ? (string) $a : kop_choice_label($a), $plain ? (string) $b : kop_choice_label($b));
            });
        }
        // Catch-alls (Other, Unknown) stay at the end.
        $last = (array) ($def['last'] ?? array('other', 'unknown', 'Other'));
        foreach ($last as $l) {
            if (array_key_exists($l, $out)) {
                $v = $out[$l];
                unset($out[$l]);
                $out[$l] = $v;
            }
        }
        if ($mode === 'offered') {
            $out = array_diff_key($out, $hidden);
        }
        return $plain ? array_values(array_map('strval', $out)) : $out;
    }
}

if (!function_exists('kop_choice_merge_item')) {
    /** A built-in choice with the screen's label and fields over it. */
    function kop_choice_merge_item($v, array $o) {
        if (is_array($v)) {
            foreach ($o as $f => $val) {
                if (in_array($f, array('hidden', 'added', 'pos'), true)) continue;
                if ($f === 'label' && trim((string) $val) === '') continue;
                $v[$f] = $val;
            }
            return $v;
        }
        return isset($o['label']) && trim((string) $o['label']) !== '' ? (string) $o['label'] : $v;
    }
}

if (!function_exists('kop_choice_hidden')) {
    /** Values of a list hidden on the screen. */
    function kop_choice_hidden($key) {
        $out = array();
        foreach ((array) (kop_choice_saved_all()[$key]['items'] ?? array()) as $value => $o) {
            if (is_array($o) && !empty($o['hidden'])) $out[] = (string) $value;
        }
        return $out;
    }
}

if (!function_exists('kop_choice_label')) {
    /** The label of a keyed choice ('' when unknown). */
    function kop_choice_label($choice) {
        if (is_array($choice)) return (string) ($choice['label'] ?? '');
        return (string) $choice;
    }
}

if (!function_exists('kop_choice_defaults')) {
    /** A list's built-in choices, read from its source function. */
    function kop_choice_defaults($key) {
        $def = kop_choice_def($key);
        if (!$def) return array();
        if (is_array($def['defaults'] ?? null)) {
            if (empty($def['plain'])) return $def['defaults'];
            $out = array();
            foreach ($def['defaults'] as $v) $out[(string) $v] = (string) $v;
            return $out;
        }
        if (empty($def['source']) || !is_callable($def['source'])) return array();
        $GLOBALS['kop_choice_capture'] = $key;
        $GLOBALS['kop_choice_captured'] = null;
        try {
            call_user_func($def['source']);
        } finally {
            unset($GLOBALS['kop_choice_capture']);
        }
        $got = $GLOBALS['kop_choice_captured'];
        unset($GLOBALS['kop_choice_captured']);
        if (!is_array($got)) return array();
        if (!empty($def['plain'])) {
            $out = array();
            foreach ($got as $v) $out[(string) $v] = (string) $v;
            return $out;
        }
        return $got;
    }
}

if (!function_exists('kop_choice_current')) {
    /** A list as its source returns it now ('all' or 'offered'), keyed value => label/array. */
    function kop_choice_current($key, $mode = 'all') {
        $def = kop_choice_def($key);
        if (!$def) return array();
        $defaults = kop_choice_defaults($key);
        $list = kop_choices($key, !empty($def['plain']) ? array_values($defaults) : $defaults, $mode);
        if (!empty($def['plain'])) {
            $out = array();
            foreach ($list as $v) $out[$v] = $v;
            return $out;
        }
        return $list;
    }
}

if (!function_exists('kop_choice_slug')) {
    /** A value for a new choice: "Court order (state)" -> court_order_state. */
    function kop_choice_slug($label, $sep = '_') {
        $s = function_exists('remove_accents') ? remove_accents((string) $label) : (string) $label;
        $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', $sep, $s) ?? '');
        $s = trim($s, $sep);
        return substr($s, 0, 40);
    }
}

if (!function_exists('kop_choice_field_options')) {
    function kop_choice_field_options(array $field) {
        $opts = $field['options'] ?? array();
        if (is_callable($opts)) $opts = call_user_func($opts);
        return (array) $opts;
    }
}

// ---------------------------------------------------------------------------
// Saving, the log and Undo.

if (!function_exists('kop_choice_write')) {
    /** Writes the store (option + the file copy) and resets the memo. */
    function kop_choice_write(array $all) {
        if (isset($GLOBALS['kop_choice_test_store'])) {
            $GLOBALS['kop_choice_test_store'] = $all;
        } else {
            update_option(KOP_CHOICE_LISTS_OPTION, $all, false);
            $path = kop_choice_mirror_path();
            if (!is_dir(dirname($path))) {
                function_exists('wp_mkdir_p') ? wp_mkdir_p(dirname($path)) : @mkdir(dirname($path), 0755, true);
            }
            @file_put_contents($path, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        kop_choice_saved_all(true);
        if (function_exists('do_action')) do_action('kop_choice_lists_saved', $all);
    }
}

if (!function_exists('kop_choice_log')) {
    function kop_choice_log() {
        if (isset($GLOBALS['kop_choice_test_log'])) return (array) $GLOBALS['kop_choice_test_log'];
        $log = function_exists('get_option') ? get_option('kop_choice_lists_log', array()) : array();
        return is_array($log) ? $log : array();
    }
}

if (!function_exists('kop_choice_log_write')) {
    function kop_choice_log_write(array $log) {
        $log = array_slice($log, 0, 200);
        if (isset($GLOBALS['kop_choice_test_log'])) $GLOBALS['kop_choice_test_log'] = $log;
        else update_option('kop_choice_lists_log', $log, false);
    }
}

if (!function_exists('kop_choice_set_items')) {
    /**
     * Sets items of one list: $changes = {value: override array, or null to
     * drop the override}. Logs each changed item (before/after) under one
     * change id. Returns the change id ('' when nothing changed).
     */
    function kop_choice_set_items($key, array $changes, $words = '') {
        $all = kop_choice_saved_all();
        $items = (array) ($all[$key]['items'] ?? array());
        $diff = array();
        foreach ($changes as $value => $after) {
            $value = (string) $value;
            $before = $items[$value] ?? null;
            if (is_array($after)) {
                $after = array_filter($after, function ($v) { return $v !== null && $v !== ''; });
                if (!$after) $after = null;
            }
            if ($before == $after) continue;
            $diff[$value] = array('before' => $before, 'after' => $after);
            if ($after === null) unset($items[$value]);
            else $items[$value] = $after;
        }
        if (!$diff) return '';
        $all[$key]['items'] = $items;
        if (!$items) unset($all[$key]);
        kop_choice_write($all);
        $id = substr(md5($key . microtime(true) . kop_choice_json($diff)), 0, 12);
        $log = kop_choice_log();
        $user = function_exists('wp_get_current_user') ? (string) (wp_get_current_user()->display_name ?? '') : '';
        array_unshift($log, array('id' => $id, 'time' => time(), 'user' => $user, 'list' => $key,
            'words' => $words, 'items' => $diff));
        kop_choice_log_write($log);
        return $id;
    }
}

if (!function_exists('kop_choice_json')) {
    function kop_choice_json($v) {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}

if (!function_exists('kop_choice_undo')) {
    /**
     * Puts back what one logged change replaced. An item changed again since
     * is left as it is now (the result says so). Returns {ok, words, skipped}.
     */
    function kop_choice_undo($id) {
        $log = kop_choice_log();
        foreach ($log as $i => $entry) {
            if (($entry['id'] ?? '') !== $id || !empty($entry['undone'])) continue;
            $key = (string) $entry['list'];
            $now = (array) (kop_choice_saved_all()[$key]['items'] ?? array());
            $changes = array();
            $skipped = array();
            foreach ((array) $entry['items'] as $value => $ba) {
                if (($now[$value] ?? null) != ($ba['after'] ?? null)) { $skipped[] = (string) $value; continue; }
                $changes[$value] = $ba['before'] ?? null;
            }
            // Written without a new log line: the entry is marked undone instead.
            $all = kop_choice_saved_all();
            $items = (array) ($all[$key]['items'] ?? array());
            foreach ($changes as $value => $before) {
                if ($before === null) unset($items[$value]);
                else $items[$value] = $before;
            }
            $all[$key]['items'] = $items;
            if (!$items) unset($all[$key]);
            kop_choice_write($all);
            $log[$i]['undone'] = time();
            kop_choice_log_write($log);
            return array('ok' => true, 'words' => (string) ($entry['words'] ?? ''), 'skipped' => $skipped);
        }
        return array('ok' => false, 'words' => '', 'skipped' => array());
    }
}

if (!function_exists('kop_choice_apply_form')) {
    /**
     * Turns the screen's form for one list into kop_choice_set_items()
     * changes. $rows = {value: {label, hidden(0/1), <field>}} for choices on
     * the list; $new = [{label, <field>}] for choices to add. Returns
     * [changes, words, errors].
     */
    function kop_choice_apply_form($key, array $rows, array $new) {
        $def = kop_choice_def($key);
        $defaults = kop_choice_defaults($key);
        $current = kop_choice_current($key, 'all');
        $items = (array) (kop_choice_saved_all()[$key]['items'] ?? array());
        $plain = !empty($def['plain']);
        $fields = (array) ($def['fields'] ?? array());
        $max = (int) ($def['max_len'] ?? 80);
        $changes = array();
        $words = array();
        $errors = array();
        $labels_taken = array();
        foreach ($current as $value => $c) $labels_taken[mb_strtolower(trim(kop_choice_label($c)))] = $value;

        foreach ($rows as $value => $row) {
            $value = (string) $value;
            if (!array_key_exists($value, $current)) continue;
            $o = isset($items[$value]) && is_array($items[$value]) ? $items[$value] : array();
            $built_in = array_key_exists($value, $defaults);
            $base = $built_in ? $defaults[$value] : null;
            $label_now = kop_choice_label($current[$value]);
            $o2 = $o;
            $hide = !empty($row['hidden']);
            if ($hide) $o2['hidden'] = 1; else unset($o2['hidden']);
            if (!$plain && isset($row['label'])) {
                $label = trim(preg_replace('/\s+/u', ' ', (string) $row['label']) ?? '');
                if ($label === '') {
                    $errors[] = 'A label cannot be empty (' . $value . ').';
                } elseif (mb_strlen($label) > $max) {
                    $errors[] = 'Labels are ' . $max . ' characters at most (' . $label . ').';
                } elseif ($built_in && $label === kop_choice_label($base)) {
                    unset($o2['label']);
                } else {
                    $o2['label'] = $label;
                }
                if ($label !== '' && $label !== $label_now) {
                    $words[] = '"' . $label_now . '" renamed "' . $label . '"';
                    $labels_taken[mb_strtolower($label)] = $value;
                }
            }
            foreach ($fields as $f => $fd) {
                if (!array_key_exists($f, $row) && ($fd['type'] ?? 'text') !== 'check') continue;
                $val = ($fd['type'] ?? 'text') === 'check' ? (!empty($row[$f]) ? 1 : 0) : trim((string) ($row[$f] ?? ''));
                $base_val = is_array($base) ? ($base[$f] ?? (($fd['type'] ?? '') === 'check' ? 0 : '')) : null;
                if ($built_in && (string) $val === (string) (is_bool($base_val) ? (int) $base_val : $base_val)) unset($o2[$f]);
                elseif (!$built_in && ($val === '' || $val === 0) && ($fd['type'] ?? '') !== 'check') unset($o2[$f]);
                else $o2[$f] = $val;
            }
            if ($o2 != $o) {
                $changes[$value] = $o2 ?: null;
                if (empty($o['hidden']) !== empty($o2['hidden'])) {
                    $words[] = '"' . $label_now . '" ' . ($hide ? 'hidden' : 'shown again');
                }
            }
        }

        $pos = 0;
        foreach ($items as $o) $pos = max($pos, (int) ($o['pos'] ?? 0));
        foreach ($new as $row) {
            $label = trim(preg_replace('/\s+/u', ' ', (string) ($row['label'] ?? '')) ?? '');
            if ($label === '') continue;
            if (mb_strlen($label) > $max) { $errors[] = 'Labels are ' . $max . ' characters at most (' . $label . ').'; continue; }
            $taken = $labels_taken[mb_strtolower($label)] ?? null;
            if ($taken !== null) {
                $errors[] = '"' . $label . '" is already on the list' . (in_array((string) $taken, kop_choice_hidden($key), true) ? ' (hidden: untick Hide to show it again)' : '') . '.';
                continue;
            }
            $value = $plain ? $label : kop_choice_slug($label, $def['slug_sep'] ?? '_');
            if ($value === '') { $errors[] = '"' . $label . '" needs at least one letter or number.'; continue; }
            if (!$plain && array_key_exists($value, $current)) {
                $n = 2;
                while (array_key_exists($value . ($def['slug_sep'] ?? '_') . $n, $current)) $n++;
                $value .= ($def['slug_sep'] ?? '_') . $n;
            }
            $o = array('added' => 1, 'pos' => ++$pos);
            if (!$plain) $o['label'] = $label;
            $missing = false;
            foreach ($fields as $f => $fd) {
                $val = ($fd['type'] ?? 'text') === 'check' ? (!empty($row[$f]) ? 1 : 0) : trim((string) ($row[$f] ?? ''));
                if (!empty($fd['required']) && ($val === '' || $val === null)) {
                    $errors[] = '"' . $label . '" needs ' . strtolower((string) $fd['label']) . '.';
                    $missing = true;
                }
                if ($val !== '' && $val !== 0) $o[$f] = $val;
            }
            if ($missing) continue;
            $changes[$value] = $o;
            $labels_taken[mb_strtolower($label)] = $value;
            $words[] = '"' . $label . '" added';
        }
        return array($changes, implode('; ', $words), $errors);
    }
}

// ---------------------------------------------------------------------------
// The browser copy.

if (function_exists('add_action')) {
    add_action('wp_enqueue_scripts', 'kop_choice_lists_enqueue', 2);
    add_action('admin_enqueue_scripts', 'kop_choice_lists_enqueue', 2);
}

if (!function_exists('kop_choice_lists_js_data')) {
    /** {list: [...]} for every list registered with 'js'. */
    function kop_choice_lists_js_data() {
        $out = array();
        foreach (kop_choice_registry() as $key => $def) {
            if (empty($def['js'])) continue;
            $list = kop_choice_current($key, 'offered');
            if (!empty($def['plain'])) {
                $out[$key] = array_values($list);
                continue;
            }
            $rows = array();
            foreach ($list as $value => $c) {
                $row = is_array($c) ? $c : array('label' => $c);
                $rows[] = array('value' => (string) $value) + $row;
            }
            $out[$key] = $rows;
        }
        return $out;
    }
}

if (!function_exists('kop_choice_lists_enqueue')) {
    function kop_choice_lists_enqueue() {
        if (!function_exists('wp_register_script')) return;
        wp_register_script('kop-choice-lists', false, array(), null, false);
        wp_enqueue_script('kop-choice-lists');
        wp_add_inline_script('kop-choice-lists', 'window.KOP_CHOICE_LISTS = ' . wp_json_encode(kop_choice_lists_js_data()) . ';');
        kop_choice_add_enqueue();
    }
}

// ---------------------------------------------------------------------------
// "Add new..." in a dropdown (js/choice-add.js).
//
// A <select> fed by a registered list carries data-kop-choice="<list key>"
// (kop_choice_select_attr()). For an admin, js/choice-add.js adds a last
// option "+ Add new..."; picking it opens a text box (and the list's extra
// fields), saves the choice through the same code as KOP Tools > Dropdown
// Lists (kop_choice_apply_form() + kop_choice_set_items(), so it is logged and
// can be undone there) and selects it. A select whose values are not a
// registered list (publication status, jurisdiction, filters, sorts) has
// nothing to save to and gets no such option.

if (!function_exists('kop_choice_select_attr')) {
    /** The attribute that makes a <select> offer "Add new..." for list $key ('' when not registered). $by_label: the options' values are the labels. */
    function kop_choice_select_attr($key, $by_label = false) {
        if (!kop_choice_def($key)) return '';
        return ' data-kop-choice="' . htmlspecialchars($key, ENT_QUOTES) . '"' . ($by_label ? ' data-kop-choice-by="label"' : '');
    }
}

if (!function_exists('kop_choice_add_meta')) {
    /** What the script needs to know of each list: label, plain, extra fields. */
    function kop_choice_add_meta() {
        $out = array();
        foreach (kop_choice_registry() as $key => $def) {
            $fields = array();
            foreach ((array) $def['fields'] as $f => $fd) {
                $opts = array();
                if (($fd['type'] ?? 'text') === 'select') {
                    foreach (kop_choice_field_options($fd) as $v => $l) $opts[] = array('value' => (string) $v, 'label' => (string) $l);
                }
                $fields[] = array('name' => (string) $f, 'label' => (string) ($fd['label'] ?? $f), 'type' => (string) ($fd['type'] ?? 'text'),
                    'options' => $opts, 'required' => !empty($fd['required']), 'help' => (string) ($fd['help'] ?? ''));
            }
            $out[$key] = array('label' => (string) $def['label'], 'plain' => !empty($def['plain']), 'max' => (int) $def['max_len'], 'fields' => $fields);
        }
        return $out;
    }
}

if (!function_exists('kop_choice_add_enqueue')) {
    function kop_choice_add_enqueue() {
        static $done = false;
        if ($done || !function_exists('current_user_can') || !current_user_can('manage_options')) return;
        $done = true;
        $dir = get_stylesheet_directory();
        $file = $dir . '/js/choice-add.js';
        if (!is_readable($file)) return;
        wp_enqueue_script('kop-choice-add', get_stylesheet_directory_uri() . '/js/choice-add.js', array('kop-choice-lists'), filemtime($file), true);
        wp_localize_script('kop-choice-add', 'KOP_CHOICE_ADD', array(
            'ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('kop_choice_add'), 'lists' => kop_choice_add_meta(),
            'manage' => admin_url('admin.php?page=' . KOP_CHOICE_LISTS_PAGE),
        ));
    }
}

if (function_exists('add_action')) {
    add_action('wp_ajax_kop_choice_add', 'kop_choice_add_ajax');
}

if (!function_exists('kop_choice_add_new')) {
    /**
     * Adds one choice to list $key. $row = {label, <field>}. Returns
     * {ok, error, value, label, change, offered}: the value the records store
     * (what the dropdown option carries), or the error to show.
     */
    function kop_choice_add_new($key, array $row) {
        $def = kop_choice_def($key);
        if (!$def) return array('ok' => false, 'error' => 'Unknown list.');
        list($changes, $words, $errors) = kop_choice_apply_form($key, array(), array($row));
        if ($errors) return array('ok' => false, 'error' => implode(' ', $errors));
        if (!$changes) return array('ok' => false, 'error' => 'Type the new choice first.');
        $value = (string) key($changes);
        $id = kop_choice_set_items($key, $changes, $words);
        $now = kop_choice_current($key, 'all');
        $c = $now[$value] ?? null;
        $label = $c === null ? trim((string) ($row['label'] ?? '')) : kop_choice_label($c);
        return array('ok' => true, 'error' => '', 'value' => $value, 'label' => $label, 'change' => $id,
            'extra' => is_array($c) ? $c : array());
    }
}

if (!function_exists('kop_choice_add_ajax')) {
    function kop_choice_add_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('error' => 'Not allowed.'), 403);
        check_ajax_referer('kop_choice_add', 'nonce');
        $key = sanitize_key(wp_unslash($_POST['list'] ?? ''));
        $row = array('label' => sanitize_text_field(wp_unslash($_POST['label'] ?? '')));
        foreach ((array) wp_unslash($_POST['fields'] ?? array()) as $f => $v) {
            if (is_scalar($v)) $row[sanitize_key($f)] = is_string($v) ? sanitize_textarea_field($v) : $v;
        }
        $r = kop_choice_add_new($key, $row);
        if (empty($r['ok'])) wp_send_json_error(array('error' => $r['error']), 200);
        wp_send_json_success($r);
    }
}

// ---------------------------------------------------------------------------
// KOP Tools > Dropdown Lists.

if (function_exists('add_action')) {
    add_action('admin_menu', function () {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Dropdown Lists', 'Dropdown Lists', 'manage_options',
            KOP_CHOICE_LISTS_PAGE, 'kop_choice_lists_page');
    }, 21);
}

if (!function_exists('kop_choice_lists_page')) {
    function kop_choice_lists_page() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', 403);
        $registry = kop_choice_registry();
        $key = sanitize_key($_GET['list'] ?? '');
        if (!isset($registry[$key])) $key = '';
        $base_url = admin_url('admin.php?page=' . KOP_CHOICE_LISTS_PAGE);
        $notice = '';
        $errors = array();
        $undo_id = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('kop_choice_lists');
            $do = sanitize_key($_POST['kop_do'] ?? '');
            if ($do === 'undo') {
                $r = kop_choice_undo(sanitize_key($_POST['change'] ?? ''));
                $notice = $r['ok'] ? 'Undone: ' . $r['words'] . '.'
                    . ($r['skipped'] ? ' Left as they are now (changed again since): ' . implode(', ', $r['skipped']) . '.' : '')
                    : 'That change was already undone.';
            } elseif ($do === 'save' && $key !== '') {
                $rows = array();
                foreach ((array) wp_unslash($_POST['rows'] ?? array()) as $value => $row) {
                    if (is_array($row)) $rows[(string) $value] = $row;
                }
                $new = array();
                foreach ((array) wp_unslash($_POST['new'] ?? array()) as $row) {
                    if (is_array($row)) $new[] = $row;
                }
                list($changes, $words, $errors) = kop_choice_apply_form($key, $rows, $new);
                if (!$errors) {
                    $undo_id = kop_choice_set_items($key, $changes, $words);
                    // Name the list and where it shows: a choice saved to the wrong list looked like a save that did nothing.
                    $notice = $undo_id !== ''
                        ? 'Saved to "' . $registry[$key]['label'] . '": ' . $words . '.'
                            . ($registry[$key]['where'] !== '' ? ' This list is used in: ' . wp_strip_all_tags($registry[$key]['where']) : '')
                        : 'Nothing changed.';
                }
            }
        }

        echo '<div class="wrap kop-choice-lists">';
        echo '<style>
            .kop-choice-lists .kcl-cols{display:grid;grid-template-columns:minmax(0,260px) minmax(0,1fr);gap:24px;align-items:start}
            @media (max-width:782px){.kop-choice-lists .kcl-cols{grid-template-columns:minmax(0,1fr)}.kop-choice-lists .kcl-main{order:-1}}
            .kop-choice-lists .kcl-nav{background:#fff;border:1px solid #c3c4c7;padding:8px 12px}
            .kop-choice-lists .kcl-nav h3{margin:12px 0 4px;font-size:12px;text-transform:uppercase;color:#4A5568}
            .kop-choice-lists .kcl-nav a{display:block;padding:3px 0;text-decoration:none}
            .kop-choice-lists .kcl-nav a.current{font-weight:600;color:#000435}
            .kop-choice-lists .kcl-nav .kcl-count{color:#4A5568;font-size:12px}
            .kop-choice-lists table.kcl-table{width:100%;border-collapse:collapse;background:#fff}
            .kop-choice-lists .kcl-table th,.kop-choice-lists .kcl-table td{border-bottom:1px solid #dcdcde;padding:6px 8px;text-align:left;vertical-align:top}
            .kop-choice-lists .kcl-table input[type=text],.kop-choice-lists .kcl-table select,.kop-choice-lists .kcl-table textarea{width:100%;max-width:100%;box-sizing:border-box}
            .kop-choice-lists .kcl-table tr.is-hidden td{background:#f6f7f7;color:#4A5568}
            .kop-choice-lists .kcl-value{font-family:monospace;font-size:12px;color:#4A5568;word-break:break-all}
            .kop-choice-lists .kcl-badge{display:inline-block;font-size:11px;padding:0 6px;border-radius:8px;background:#B6E3D4;color:#000435}
            .kop-choice-lists .kcl-note{background:#FFF5CB;border-left:4px solid #EF9034;padding:8px 12px;max-width:760px}
            .kop-choice-lists .kcl-log li{margin:4px 0}
            .kop-choice-lists .kcl-log form{display:inline}
            .kop-choice-lists .kcl-scroll{overflow-x:auto}
        </style>';
        echo '<h1>Dropdown Lists</h1>';
        echo '<p style="max-width:760px">The choices in the site\'s dropdowns and category lists. Pick a list, add choices, '
            . 'rename a label or hide a choice you no longer want offered. A hidden choice stays on the records that '
            . 'already use it; it is only left out of the dropdowns. Every save can be undone below.</p>';
        if ($notice !== '') echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        foreach ($errors as $e) echo '<div class="notice notice-error"><p>' . esc_html($e) . '</p></div>';

        echo '<div class="kcl-cols"><nav class="kcl-nav" aria-label="Lists">';
        $group = null;
        foreach ($registry as $k => $d) {
            if ($d['group'] !== $group) {
                $group = $d['group'];
                echo '<h3>' . esc_html($group) . '</h3>';
            }
            $n = count(kop_choice_current($k, 'offered'));
            echo '<a href="' . esc_url(add_query_arg('list', $k, $base_url)) . '"' . ($k === $key ? ' class="current" aria-current="page"' : '') . '>'
                . esc_html($d['label']) . ' <span class="kcl-count">(' . $n . ')</span></a>';
        }
        echo '</nav><div class="kcl-main">';

        if ($key === '') {
            echo '<p>Pick a list on the left.</p>';
        } else {
            kop_choice_lists_page_list($key, $registry[$key], $base_url, $errors ? ($_POST ?? array()) : array());
        }
        kop_choice_lists_page_log($key, $base_url);
        echo '</div></div></div>';
    }
}

if (!function_exists('kop_choice_field_input')) {
    function kop_choice_field_input($name, array $fd, $val) {
        $type = $fd['type'] ?? 'text';
        if ($type === 'check') {
            return '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . (!empty($val) ? ' checked' : '') . '> '
                . esc_html($fd['check_label'] ?? 'Yes') . '</label>';
        }
        if ($type === 'select') {
            $h = '<select name="' . esc_attr($name) . '">';
            if (empty($fd['required'])) $h .= '<option value="">-</option>';
            foreach (kop_choice_field_options($fd) as $ov => $ol) {
                if (is_int($ov)) $ov = $ol;
                $h .= '<option value="' . esc_attr($ov) . '"' . ((string) $val === (string) $ov ? ' selected' : '') . '>' . esc_html($ol) . '</option>';
            }
            return $h . '</select>';
        }
        if ($type === 'textarea') {
            return '<textarea name="' . esc_attr($name) . '" rows="2">' . esc_textarea((string) $val) . '</textarea>';
        }
        return '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '">';
    }
}

if (!function_exists('kop_choice_lists_page_list')) {
    function kop_choice_lists_page_list($key, array $def, $base_url, array $posted) {
        $plain = !empty($def['plain']);
        $fields = (array) $def['fields'];
        $defaults = kop_choice_defaults($key);
        $current = kop_choice_current($key, 'all');
        $hidden = array_flip(kop_choice_hidden($key));

        echo '<h2>' . esc_html($def['label']) . '</h2>';
        if ($def['where'] !== '') echo '<p style="max-width:760px">' . wp_kses_post($def['where']) . '</p>';
        if ($def['note'] !== '') echo '<p class="kcl-note">' . wp_kses_post($def['note']) . '</p>';

        echo '<form method="post" action="' . esc_url(add_query_arg('list', $key, $base_url)) . '">';
        wp_nonce_field('kop_choice_lists');
        echo '<div class="kcl-scroll"><table class="kcl-table"><thead><tr><th>' . ($plain ? 'Choice' : 'Label') . '</th>';
        foreach ($fields as $fd) echo '<th>' . esc_html($fd['label']) . '</th>';
        echo '<th>Hide</th></tr></thead><tbody>';
        foreach ($current as $value => $c) {
            $value = (string) $value;
            $is_hidden = isset($hidden[$value]);
            $built_in = array_key_exists($value, $defaults);
            $name = 'rows[' . $value . ']';
            echo '<tr' . ($is_hidden ? ' class="is-hidden"' : '') . '><td>';
            if ($plain) {
                echo esc_html($value);
                echo '<input type="hidden" name="' . esc_attr($name) . '[keep]" value="1">';
            } else {
                echo '<input type="text" name="' . esc_attr($name) . '[label]" value="' . esc_attr(kop_choice_label($c)) . '" aria-label="Label for ' . esc_attr($value) . '">';
                echo '<div class="kcl-value">' . esc_html($value) . '</div>';
            }
            if (!$built_in) echo ' <span class="kcl-badge">Added</span>';
            echo '</td>';
            foreach ($fields as $f => $fd) {
                $val = is_array($c) ? ($c[$f] ?? '') : '';
                if (is_bool($val)) $val = (int) $val;
                echo '<td>' . kop_choice_field_input($name . '[' . $f . ']', $fd, $val) . '</td>';
            }
            echo '<td><label><input type="checkbox" name="' . esc_attr($name) . '[hidden]" value="1"' . ($is_hidden ? ' checked' : '') . '> Hide</label></td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<h3>Add choices</h3>';
        echo '<div class="kcl-scroll"><table class="kcl-table"><thead><tr><th>' . ($plain ? 'Choice' : 'Label') . '</th>';
        foreach ($fields as $fd) {
            echo '<th>' . esc_html($fd['label']) . (!empty($fd['required']) ? ' (needed)' : '') . '</th>';
        }
        echo '</tr></thead><tbody>';
        $posted_new = (array) ($posted['new'] ?? array());
        for ($i = 0; $i < 3; $i++) {
            $pn = (array) ($posted_new[$i] ?? array());
            echo '<tr><td><input type="text" name="new[' . $i . '][label]" value="' . esc_attr(wp_unslash((string) ($pn['label'] ?? ''))) . '" aria-label="New choice ' . ($i + 1) . '"></td>';
            foreach ($fields as $f => $fd) {
                echo '<td>' . kop_choice_field_input('new[' . $i . '][' . $f . ']', $fd, wp_unslash((string) ($pn[$f] ?? ($fd['default'] ?? '')))) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        foreach ($fields as $fd) {
            if (!empty($fd['help'])) echo '<p class="description"><strong>' . esc_html($fd['label']) . ':</strong> ' . esc_html($fd['help']) . '</p>';
        }
        echo '<p><button type="submit" name="kop_do" value="save" class="button button-primary">Save ' . esc_html(strtolower($def['label'])) . '</button></p>';
        echo '</form>';
    }
}

if (!function_exists('kop_choice_lists_page_log')) {
    function kop_choice_lists_page_log($key, $base_url) {
        $registry = kop_choice_registry();
        $rows = array();
        foreach (kop_choice_log() as $e) {
            if ($key !== '' && ($e['list'] ?? '') !== $key) continue;
            $rows[] = $e;
            if (count($rows) >= 25) break;
        }
        echo '<h2>Recent changes' . ($key !== '' ? ' to this list' : '') . '</h2>';
        if (!$rows) { echo '<p>None yet.</p>'; return; }
        echo '<ul class="kcl-log">';
        foreach ($rows as $e) {
            $label = $registry[$e['list']]['label'] ?? $e['list'];
            echo '<li>' . esc_html(wp_date('M j, Y g:i a', (int) $e['time'])) . ' &middot; '
                . ($key === '' ? '<strong>' . esc_html($label) . ':</strong> ' : '')
                . esc_html((string) $e['words']) . ($e['user'] !== '' ? ' <span class="kcl-count">(' . esc_html($e['user']) . ')</span>' : '') . ' ';
            if (!empty($e['undone'])) {
                echo '<em>undone</em>';
            } else {
                echo '<form method="post" action="' . esc_url($key !== '' ? add_query_arg('list', $key, $base_url) : $base_url) . '">';
                wp_nonce_field('kop_choice_lists');
                echo '<input type="hidden" name="change" value="' . esc_attr($e['id']) . '">'
                    . '<button type="submit" name="kop_do" value="undo" class="button button-small">Undo</button></form>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}

// The lists themselves, one file per group (no WordPress needed).
foreach ((array) glob(__DIR__ . '/choice-lists/*.php') as $kop_choice_file) {
    require_once $kop_choice_file;
}
unset($kop_choice_file);
