<?php
/**
 * The directory's Alternate names table (js/tti-program-index.js, /tti-program-index/):
 * an admin ticks duplicate names of one record and folds them into one name.
 *
 *   POST kop/v1/directory-names/fold  {ref: 'facility:<id>' | 'operator:<id>', remove: [names], keep: name, keep_as: name}
 *   POST kop/v1/directory-names/undo  {token}
 *
 * Names are matched the way the page lists them: a stored entry "A, B" is two names,
 * "Previously X" is X, case and spacing aside. Removed names leave the record's
 * pastNames and otherNames; the kept one is rewritten when keep_as differs. Saved
 * through the pencil dialogs' save paths (kop_ie_facility_save, kop_ie_operator_save).
 * Every fold is logged with the lists before and after (option kop_dirnames_log);
 * Undo puts the lists back while nothing has changed them since.
 * Test: scripts/test-directory-names.php
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The page's name key: no "Previously"/"Formerly" lead-in, no trailing "(", spacing and case aside. */
function kop_dirnames_key($name) {
    $s = trim(preg_replace('/\s+/u', ' ', (string) $name));
    $s = trim(preg_replace('/\s*\($/', '', $s));
    $s = trim(preg_replace('/^\s*(?:previously|formerly|former|prior(?:\s+to)?)\b[\s:;,.\-–—]*/iu', '', $s));
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** A stored entry split as the page splits it ("Foo, Inc." stays whole). */
function kop_dirnames_split($entry) {
    $out = array();
    foreach (preg_split('/\s*[;,]\s*/u', trim((string) $entry)) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if ($out && preg_match('/^(?:inc|llc|l\.l\.c|ltd|co|corp|corporation|company|llp|lp|plc|pllc|pc|p\.c|n\.a)\.?$/i', $part)) {
            $out[count($out) - 1] .= ', ' . $part;
        } else {
            $out[] = $part;
        }
    }
    return $out;
}

/**
 * Fold names in two lists (pastNames, otherNames as stored). Returns the new lists and
 * which asked-for names were not found in either. $remove and $keep are display names.
 */
function kop_dirnames_fold_lists(array $lists, array $remove, $keep, $keep_as) {
    $drop = array();
    foreach ($remove as $n) {
        $k = kop_dirnames_key($n);
        if ($k !== '') $drop[$k] = $n;
    }
    $keep_key = kop_dirnames_key($keep);
    unset($drop[$keep_key]);
    $keep_as = trim(preg_replace('/\s+/u', ' ', (string) $keep_as));
    $found = array();
    $out = array();
    foreach ($lists as $field => $list) {
        $new = array();
        foreach ((array) $list as $entry) {
            if (!is_string($entry)) {
                $new[] = $entry;
                continue;
            }
            $parts = kop_dirnames_split($entry);
            $kept = array();
            $changed = false;
            foreach ($parts as $part) {
                $k = kop_dirnames_key($part);
                if (isset($drop[$k])) {
                    $found[$k] = true;
                    $changed = true;
                    continue;
                }
                if ($k === $keep_key && $keep_as !== '' && $keep_as !== $part) {
                    $found[$k] = true;
                    $part = $keep_as;
                    $changed = true;
                } elseif ($k === $keep_key) {
                    $found[$k] = true;
                }
                $kept[] = $part;
            }
            if (!$changed) {
                $new[] = $entry;
            } elseif ($kept) {
                $new[] = implode(', ', $kept);
            }
        }
        // One name never twice in a list.
        $seen = array();
        $out[$field] = array_values(array_filter($new, function ($e) use (&$seen) {
            if (!is_string($e)) return true;
            $k = kop_dirnames_key($e);
            if (isset($seen[$k])) return false;
            $seen[$k] = true;
            return true;
        }));
    }
    $missing = array();
    foreach ($drop as $k => $n) {
        if (!isset($found[$k])) $missing[] = $n;
    }
    return array($out, $missing);
}

/** array(kind, id, label, lists) for a ref, the lists as stored now. */
function kop_dirnames_load($ref) {
    if (!preg_match('/^(facility|operator):(\d+)$/', (string) $ref, $m)) {
        throw new RuntimeException('Unknown record.');
    }
    $id = (int) $m[2];
    $opts = kop_ie_facility_opts();
    if ($m[1] === 'facility') {
        $stored = kop_facility_load($id, $opts);
        if (!$stored) throw new RuntimeException("Facility #{$id} does not exist.");
        $ident = $stored['doc']['identification'] ?? array();
        return array('facility', $id, (string) ($ident['name'] ?? ''), array(
            'pastNames'  => array_values((array) ($ident['pastNames'] ?? array())),
            'otherNames' => array_values((array) ($ident['otherNames'] ?? array())),
        ));
    }
    list($row, $json) = kop_ie_operator_row($opts['pdo'], $id);
    $op = $json['operator'];
    return array('operator', $id, (string) $row['name'], array(
        'pastNames'  => array_values((array) ($op['pastNames'] ?? array())),
        'otherNames' => array_values((array) ($op['otherNames'] ?? array())),
    ));
}

/** Writes the two lists through the pencil dialogs' save path. */
function kop_dirnames_write($kind, $id, array $lists) {
    $lines = function ($list) {
        return implode("\n", array_map('strval', array_filter((array) $list, 'is_string')));
    };
    if ($kind === 'facility') {
        kop_ie_facility_save(array($id, 'names'), array(
            'identification.pastNames'  => $lines($lists['pastNames']),
            'identification.otherNames' => $lines($lists['otherNames']),
        ));
    } else {
        kop_ie_operator_save(array($id, 'names'), array(
            'pastNames'  => $lines($lists['pastNames']),
            'otherNames' => $lines($lists['otherNames']),
        ));
    }
}

function kop_dirnames_same(array $a, array $b) {
    $norm = function ($lists) {
        $o = array();
        foreach (array('pastNames', 'otherNames') as $f) {
            $o[$f] = array_values(array_filter(array_map('trim', array_map('strval', array_filter((array) ($lists[$f] ?? array()), 'is_string'))), 'strlen'));
        }
        return $o;
    };
    return $norm($a) === $norm($b);
}

/** The fold. Returns array(message, token, lists). */
function kop_dirnames_fold($ref, array $remove, $keep, $keep_as) {
    $remove = array_values(array_filter(array_map('strval', $remove), function ($n) {
        return trim($n) !== '';
    }));
    if (trim((string) $keep) === '' || !$remove) {
        throw new RuntimeException('Pick the name to keep and at least one to fold into it.');
    }
    list($kind, $id, $label, $before) = kop_dirnames_load($ref);
    list($after, $missing) = kop_dirnames_fold_lists($before, $remove, $keep, $keep_as);
    if ($missing) {
        throw new RuntimeException('Not in the record\'s past or other names, so not changed here: ' . implode('; ', $missing)
            . '. It may be the record\'s own name or a legal name in its owners; use Edit names.');
    }
    if (kop_dirnames_same($before, $after)) {
        return array('message' => 'Nothing to change.', 'token' => '', 'lists' => $before);
    }
    kop_dirnames_write($kind, $id, $after);
    list(, , , $saved) = kop_dirnames_load($ref);
    $token = wp_generate_password(16, false);
    $log = get_option('kop_dirnames_log', array());
    if (!is_array($log)) $log = array();
    $log[] = array(
        'token' => $token, 'ref' => $ref, 'label' => $label, 'before' => $before, 'after' => $saved,
        'remove' => $remove, 'keep' => (string) $keep, 'keep_as' => (string) $keep_as,
        'by' => get_current_user_id(), 'at' => current_time('mysql'), 'undone' => false,
    );
    update_option('kop_dirnames_log', array_slice($log, -200), false);
    $n = count($remove);
    return array(
        'message' => sprintf('%s: %d %s folded into "%s".', $label, $n, $n === 1 ? 'name' : 'names', trim((string) $keep_as) !== '' ? trim((string) $keep_as) : $keep),
        'token'   => $token,
        'lists'   => $saved,
    );
}

function kop_dirnames_undo($token) {
    $log = get_option('kop_dirnames_log', array());
    foreach ((array) $log as $i => $entry) {
        if (($entry['token'] ?? '') !== (string) $token || $token === '') continue;
        if (!empty($entry['undone'])) throw new RuntimeException('Already undone.');
        list($kind, $id, , $now) = kop_dirnames_load($entry['ref']);
        if (!kop_dirnames_same($now, $entry['after'])) {
            throw new RuntimeException('The names have changed since; undo by hand with Edit names.');
        }
        kop_dirnames_write($kind, $id, $entry['before']);
        $log[$i]['undone'] = true;
        update_option('kop_dirnames_log', $log, false);
        return array('message' => 'Undone: ' . $entry['label'] . ' has its names back.');
    }
    throw new RuntimeException('That fold is not in the log.');
}

add_action('rest_api_init', function () {
    $can = function () {
        return current_user_can('manage_options');
    };
    register_rest_route('kop/v1', '/directory-names/fold', array(
        'methods'             => 'POST',
        'permission_callback' => $can,
        'callback'            => function (WP_REST_Request $r) {
            try {
                return rest_ensure_response(kop_dirnames_fold(
                    (string) $r->get_param('ref'),
                    (array) $r->get_param('remove'),
                    (string) $r->get_param('keep'),
                    (string) $r->get_param('keep_as')
                ));
            } catch (Throwable $e) {
                return new WP_Error('kop_dirnames', $e->getMessage(), array('status' => 400));
            }
        },
    ));
    register_rest_route('kop/v1', '/directory-names/undo', array(
        'methods'             => 'POST',
        'permission_callback' => $can,
        'callback'            => function (WP_REST_Request $r) {
            try {
                return rest_ensure_response(kop_dirnames_undo((string) $r->get_param('token')));
            } catch (Throwable $e) {
                return new WP_Error('kop_dirnames', $e->getMessage(), array('status' => 400));
            }
        },
    ));
});
