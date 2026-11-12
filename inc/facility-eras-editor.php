<?php
/**
 * Names and eras of a facility in one dialog: the facility page's "Names and
 * eras" pencil (eras:<facility id>, inc/inline-edit.php's sources).
 *
 * One row per name, earliest first: the name, the company that ran it under
 * that name, and its first and last year. The rows are every name the record
 * has (its name, name now, past names, names merged into it as a rename), the
 * names the network map joins to it by rename lines (some with records of
 * their own), and other names that have an era set by hand.
 *
 * Save writes each changed row where it lives, so no other screen is needed:
 *   - company and years: the hand-set eras (inc/name-eras.php), which the
 *     facility page, the directory and the "Formerly" line read first;
 *   - a new row: the name is added to the record's past names; a removed past
 *     name comes off the record (its own name, a map name or another record's
 *     cannot be removed here);
 *   - the year one name gave way to the next: on a program merged as a rename,
 *     its rename entry (legacy.mergedFacilities, as the "As <name>" pencil
 *     writes it); on names the map joins, the Map Renames decision for that
 *     line (year, and a swap when the years put the line's names the other
 *     way round). A record with neither is cut by the hand-set eras alone
 *     (kop_facility_eras_hand_chain()).
 *   - the most popular name (identification.popularName, the name people know
 *     it by, shown first wherever the facility is named): a pick among the
 *     record's names, as on the names pencil (kop_ie_popular_name_options()).
 * Every save can be undone in one go (erasundo:<token>) while nothing it wrote
 * has changed since.
 *
 * Test: scripts/test-eras-editor.php
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('kop_inline_edit_sources', function ($sources) {
    $sources['eras'] = array('load' => 'kop_eras_ed_load', 'save' => 'kop_eras_ed_save');
    $sources['erasundo'] = array('load' => 'kop_eras_ed_undo_load', 'save' => 'kop_eras_ed_undo_save');
    return $sources;
});

/** The record's document for $fid, with facility_id set, and its stored row. */
function kop_eras_ed_stored($fid, array $opts = null) {
    $stored = kop_facility_load((int) $fid, $opts ?: kop_ie_facility_opts((int) $fid));
    if (!$stored) {
        throw new RuntimeException("Facility #{$fid} does not exist.");
    }
    if (!isset($stored['doc']['facility_id'])) {
        $stored['doc']['facility_id'] = (int) $fid;
    }
    return $stored;
}

/** Past names as the record lists them, each entry split into its names. */
function kop_eras_ed_past_names(array $doc) {
    $out = array();
    foreach ((array) ($doc['identification']['pastNames'] ?? array()) as $entry) {
        if (!is_string($entry)) continue;
        foreach (function_exists('kop_dirnames_split') ? kop_dirnames_split($entry) : array($entry) as $n) {
            $n = trim($n);
            if ($n !== '') $out[kop_name_eras_key($n)] = $n;
        }
    }
    return $out;
}

/**
 * The dialog's rows, earliest first: id => {id, name, key, owner, kind,
 * company, from, to, node, merged, hand}. kind: own (the record's name or
 * name now), past, merged (an earlier name merged in as a rename), other
 * (also known as), map (a name the map joins, with no record), record (the
 * name of another record the map joins). owner: the record whose era it is.
 * hand: the era set by hand (inc/name-eras.php), else null.
 */
function kop_eras_ed_rows($fid, array $doc) {
    $fid = (int) $fid;
    $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
    $own = array();
    foreach (array('name', 'currentName') as $f) {
        $n = trim((string) ($ident[$f] ?? ''));
        if ($n !== '') $own[kop_name_eras_key($n)] = $n;
    }
    $past = kop_eras_ed_past_names($doc);
    $other = array();
    foreach ((array) ($ident['otherNames'] ?? array()) as $entry) {
        if (!is_string($entry)) continue;
        foreach (function_exists('kop_dirnames_split') ? kop_dirnames_split($entry) : array($entry) as $n) {
            if (trim($n) !== '') $other[kop_name_eras_key($n)] = trim($n);
        }
    }
    $merged = array();
    foreach ((array) ($doc['legacy']['mergedFacilities'] ?? array()) as $m) {
        if (is_array($m) && is_array($m['rename'] ?? null) && trim((string) ($m['name'] ?? '')) !== '') $merged[kop_name_eras_key($m['name'])] = trim($m['name']);
    }
    $hand = kop_name_eras_for($fid);
    $rows = array();
    $add = function ($name, $owner, $kind, array $extra = array()) use (&$rows, $fid, $hand) {
        $key = kop_name_eras_key($name);
        if ($key === '') return;
        $id = $key . '@' . (int) $owner;
        if (isset($rows[$id])) return;
        $e = (int) $owner === $fid ? ($hand[$key] ?? null) : (kop_name_eras_for((int) $owner)[$key] ?? null);
        $rows[$id] = array_merge(array(
            'id' => $id, 'name' => trim($name), 'key' => $key, 'owner' => (int) $owner, 'kind' => $kind,
            'company' => $e ? (string) $e['operator_name'] : '', 'from' => $e && (int) $e['start_year'] ? (int) $e['start_year'] : null,
            'to' => $e && (int) $e['end_year'] ? (int) $e['end_year'] : null, 'node' => '', 'merged' => null, 'hand' => $e,
        ), $extra);
    };
    $kind_of = function ($key) use ($own, $past, $merged, $other) {
        if (isset($own[$key])) return 'own';
        if (isset($merged[$key])) return 'merged';
        if (isset($past[$key])) return 'past';
        if (isset($other[$key])) return 'other';
        return '';
    };

    // The names in order, as the page cuts them (hand-set years already over them).
    foreach (kop_facility_eras_chain($fid, $doc) as $era) {
        $owner = (int) ($era['facility_id'] ?? 0);
        $key = kop_name_eras_key($era['name']);
        $kind = ($owner === $fid || $owner <= 0) ? ($kind_of($key) ?: 'map') : 'record';
        // A name the map joins with no record of its own keeps its era on this record.
        if ($owner <= 0) $owner = $fid;
        $add($era['name'], $owner, $kind, array(
            'node' => (string) ($era['node'] ?? ''), 'merged' => $era['merged'] ?? null,
            'company' => '', 'from' => $era['start'] ? (int) $era['start'] : null, 'to' => $era['end'] ? (int) $era['end'] : null,
        ));
        // The chain's company list may name several; the row shows them, a save writes only what changed.
        $id = $key . '@' . $owner;
        if (isset($rows[$id])) $rows[$id]['company'] = implode(', ', array_values(array_filter(array_map('strval', (array) ($era['operators'] ?? array())), 'strlen')));
    }
    // The record's other names.
    foreach ($own as $n) $add($n, $fid, 'own');
    foreach ($merged as $n) $add($n, $fid, 'merged');
    foreach ($past as $n) $add($n, $fid, 'past');
    foreach ($hand as $key => $e) {
        if ($kind_of($key) === 'other') $add($e['name'], $fid, 'other');
    }

    // Earliest first: the chain's order holds; names with no year go after the dated ones, the record's own name last.
    $i = 0;
    $sorted = array();
    foreach ($rows as $id => $r) {
        $year = (int) ($r['from'] ?: $r['to']);
        $sorted[] = array($year ?: ($r['kind'] === 'own' ? 9999 : 9998), $i++, $id);
    }
    usort($sorted, static function ($a, $b) { return ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]); });
    $out = array();
    foreach ($sorted as $s) $out[$s[2]] = $rows[$s[2]];
    return $out;
}

function kop_eras_ed_load(array $p) {
    $fid = (int) ($p[0] ?? 0);
    $stored = kop_eras_ed_stored($fid);
    $doc = $stored['doc'];
    $rows = kop_eras_ed_rows($fid, $doc);
    $value = array();
    $notes = array();
    foreach ($rows as $r) {
        $value[] = array('__i' => $r['id'], 'name' => $r['name'], 'company' => $r['company'],
            'from' => $r['from'] ? (string) $r['from'] : '', 'to' => $r['to'] ? (string) $r['to'] : '');
        if ($r['kind'] === 'record') $notes[] = $r['name'] . ' has its own record; its row is saved there.';
        if ($r['kind'] === 'map') $notes[] = $r['name'] . ' is joined to this record on the network map.';
    }
    $help = 'One row a name, earliest first: the company that ran it under that name and its first and last year. '
        . 'The years split the page into an "As <name>" section for each name, and set the year of the rename on the network map. '
        . 'Add a row for a name the record does not have yet (it is added as a past name); Remove takes a past name off the record. '
        . 'Leave company and years empty when they are not known. '
        . 'Most popular name: the name people know it by, shown first wherever the facility is named (a name added here can be picked after saving).';
    if ($notes) $help .= ' ' . implode(' ', $notes);
    return array(
        'title'  => (string) ($doc['identification']['name'] ?? '') . ': names and eras',
        'help'   => $help,
        'fields' => array(
            kop_ie_field('eras', 'Names', 'rows', $value, array('columns' => array(
                'name' => 'Name', 'company' => 'Run by (company)', 'from' => 'From (year)', 'to' => 'To (year)',
            ))),
            kop_eras_ed_popular_field($doc),
        ),
        'alt'    => array('ref' => 'facility:' . $fid . ':names', 'label' => 'Name fields'),
    );
}

/** The "Most popular name" pick: the record's names (kop_ie_popular_name_options()), its stored choice selected. */
function kop_eras_ed_popular_field(array $doc) {
    $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
    $options = kop_ie_popular_name_options($ident);
    $value = (string) ($ident['popularName'] ?? '');
    $known = false;
    foreach ($options as $o) $known = $known || $o['value'] === $value;
    return kop_ie_field('popular', 'Most popular name (shown first wherever it is named)', 'select', $known ? $value : '', array('options' => $options));
}

/** The rows a save was sent, checked: [{id, name, key, company, from, to}]. */
function kop_eras_ed_parse(array $in) {
    $max = (int) gmdate('Y') + 1;
    $out = array();
    $seen = array();
    foreach ($in as $row) {
        if (!is_array($row)) continue;
        $name = trim(preg_replace('/\s+/u', ' ', kop_ie_clean_text($row['name'] ?? '')));
        $company = trim(preg_replace('/\s+/u', ' ', kop_ie_clean_text($row['company'] ?? '')));
        $from = kop_ie_int($row['from'] ?? '', ($name !== '' ? $name : 'A row') . ', from', 1800, $max);
        $to = kop_ie_int($row['to'] ?? '', ($name !== '' ? $name : 'A row') . ', to', 1800, $max);
        if ($name === '') {
            if ($company !== '' || $from || $to) throw new RuntimeException('A row with a company or years has no name.');
            continue;
        }
        if ($from && $to && $from > $to) throw new RuntimeException("{$name}: the first year is after the last year.");
        $key = kop_name_eras_key($name);
        if (isset($seen[$key])) throw new RuntimeException("{$name} is on two rows.");
        $seen[$key] = true;
        $out[] = array('id' => (string) ($row['__i'] ?? ''), 'name' => $name, 'key' => $key, 'company' => $company, 'from' => $from, 'to' => $to);
    }
    return $out;
}

/** pastNames with names taken off ($drop keys) and renamed ($rename key => new name); entries left alone stay as written. */
function kop_eras_ed_rewrite_past(array $past, array $drop, array $rename) {
    $out = array();
    foreach ($past as $entry) {
        if (!is_string($entry)) {
            $out[] = $entry;
            continue;
        }
        $parts = function_exists('kop_dirnames_split') ? kop_dirnames_split($entry) : array($entry);
        $touched = false;
        $keep = array();
        foreach ($parts as $n) {
            $k = kop_name_eras_key($n);
            if (isset($drop[$k])) {
                $touched = true;
            } elseif (isset($rename[$k])) {
                $touched = true;
                $keep[] = $rename[$k];
            } else {
                $keep[] = trim($n);
            }
        }
        if (!$touched) {
            $out[] = $entry;
        } else {
            foreach ($keep as $n) $out[] = $n;
        }
    }
    return array_values($out);
}

function kop_eras_ed_save(array $p, array $v) {
    $fid = (int) ($p[0] ?? 0);
    kop_name_eras_install();
    $opts = kop_ie_facility_opts($fid);
    $sent = kop_eras_ed_parse((array) ($v['eras'] ?? array()));
    // null: not sent (an older dialog), left as it is.
    $popular_sent = array_key_exists('popular', $v) ? trim(kop_ie_clean_text((string) $v['popular'])) : null;
    $log = array('fid' => $fid, 'eras' => array(), 'doc' => null, 'map' => array(), 'by' => get_current_user_id(), 'at' => current_time('mysql'));
    $owners = array($fid => true);

    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $opts, $sent, $popular_sent, &$log, &$owners) {
        $stored = kop_eras_ed_stored($fid, $opts);
        $doc = $stored['doc'];
        $loaded = kop_eras_ed_rows($fid, $doc);
        $by_key = array();
        foreach ($loaded as $r) $by_key[$r['key']][] = $r['id'];

        // Each sent row against the row it was loaded as (by id, else by name).
        $final = array();
        $used = array();
        $drop = array();
        $rename = array();
        $add = array();
        foreach ($sent as $s) {
            $was = isset($loaded[$s['id']]) && !isset($used[$s['id']]) ? $loaded[$s['id']] : null;
            if ($was && $was['key'] !== $s['key']) {
                if ($was['kind'] !== 'past') {
                    throw new RuntimeException("{$was['name']} cannot be renamed here; change it with the record's name fields" . ($was['kind'] === 'record' ? ' on its own page.' : '.'));
                }
                if (!empty($by_key[$s['key']])) throw new RuntimeException("{$s['name']} is already one of its names.");
                $rename[$was['key']] = $s['name'];
            }
            if (!$was && !empty($by_key[$s['key']])) {
                foreach ($by_key[$s['key']] as $id) {
                    if (!isset($used[$id])) {
                        $was = $loaded[$id];
                        break;
                    }
                }
            }
            if ($was) {
                $used[$was['id']] = true;
            } else {
                $add[] = $s['name'];
            }
            $final[] = array('sent' => $s, 'was' => $was);
        }
        foreach ($loaded as $id => $r) {
            if (isset($used[$id])) continue;
            if ($r['kind'] === 'past') {
                $drop[$r['key']] = true;
            } elseif ($r['kind'] !== 'other') {
                $why = array('own' => 'is the record\'s own name', 'merged' => 'was merged into this record as a rename',
                    'map' => 'is joined to it on the network map (Map Renames can skip that line)', 'record' => 'has its own record');
                throw new RuntimeException("{$r['name']} {$why[$r['kind']]}, so it cannot be removed here.");
            }
        }

        // The record's names: added, renamed and dropped past names.
        $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
        $past_before = array_values((array) ($ident['pastNames'] ?? array()));
        $past_after = kop_eras_ed_rewrite_past($past_before, $drop, $rename);
        foreach ($add as $n) $past_after[] = $n;
        $merged_before = (array) ($doc['legacy']['mergedFacilities'] ?? array());
        $doc['identification']['pastNames'] = $past_after;

        // The most popular name: one of the dialog's choices; a renamed past name takes its pick along.
        $popular_before = (string) ($ident['popularName'] ?? '');
        $popular_after = $popular_sent === null ? $popular_before : $popular_sent;
        if ($popular_after !== '') {
            $allowed = array();
            foreach (kop_ie_popular_name_options($ident) as $o) $allowed[mb_strtolower($o['value'])] = true;
            if (!isset($allowed[mb_strtolower($popular_after)])) throw new RuntimeException('Most popular name: pick one of its names.');
            $pk = kop_name_eras_key($popular_after);
            if (isset($rename[$pk])) $popular_after = $rename[$pk];
        }
        $doc['identification']['popularName'] = $popular_after;

        // Era rows: what changed from what the dialog showed, written as set by hand.
        $era_changes = array();
        foreach ($final as $f) {
            $s = $f['sent'];
            $was = $f['was'];
            $owner = $was ? (int) $was['owner'] : $fid;
            $same = $was && $was['key'] === $s['key'] && $was['company'] === $s['company'] && (int) $was['from'] === (int) $s['from'] && (int) $was['to'] === (int) $s['to'];
            if ($same) continue;
            if ($was && $was['key'] !== $s['key']) {
                $era_changes[] = array('owner' => $owner, 'key' => $was['key'], 'after' => null);
            }
            $after = ($s['company'] === '' && !$s['from'] && !$s['to']) ? null : array(
                'name' => $s['name'], 'operator_id' => $s['company'] !== '' ? kop_name_eras_find_operator($s['company']) : 0,
                'operator_name' => $s['company'], 'start_year' => (int) $s['from'], 'end_year' => (int) $s['to'],
            );
            $era_changes[] = array('owner' => $owner, 'key' => $s['key'], 'after' => $after);
        }
        foreach ($drop as $key => $_) $era_changes[] = array('owner' => $fid, 'key' => $key, 'after' => null);
        foreach ($loaded as $id => $r) {
            if (!isset($used[$id]) && $r['kind'] === 'other' && $r['hand']) $era_changes[] = array('owner' => $fid, 'key' => $r['key'], 'after' => null);
        }

        // The year each name gave way to the next, in the order the years give.
        $order = array();
        foreach ($final as $i => $f) {
            $y = (int) ($f['sent']['from'] ?: $f['sent']['to']);
            $order[] = array($y ?: 99999, $i);
        }
        usort($order, static function ($a, $b) { return ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]); });
        $ordered = array();
        foreach ($order as $o) $ordered[] = $final[$o[1]]['sent'];
        $pos = array();
        foreach ($ordered as $i => $s) $pos[$s['key']] = $i;
        $cut_between = static function ($earlier, $later) {
            return (int) ($later['from'] ?: $earlier['to']);
        };

        // A program merged as a rename: each earlier name's rename entry.
        foreach ($doc['legacy']['mergedFacilities'] ?? array() as $at => $m) {
            if (!is_array($m) || !is_array($m['rename'] ?? null)) continue;
            $k = kop_name_eras_key($m['name'] ?? '');
            if (!isset($pos[$k])) continue;
            $s = $ordered[$pos[$k]];
            $next = $ordered[$pos[$k] + 1] ?? null;
            $r = $m['rename'];
            if ($s['from']) $r['startYear'] = (int) $s['from']; else unset($r['startYear']);
            if ($s['to']) $r['endYear'] = (int) $s['to']; else unset($r['endYear']);
            if ($next && ($cut = $cut_between($s, $next))) $r['year'] = $cut;
            $doc['legacy']['mergedFacilities'][$at]['rename'] = $r;
        }
        $merged_after = (array) ($doc['legacy']['mergedFacilities'] ?? array());

        if ($past_after !== $past_before || $merged_after !== $merged_before || $popular_after !== $popular_before) {
            $out = kop_facility_normalize($doc, array('facility_id' => $doc['facility_id'] ?? $fid, 'unique_name' => $stored['unique_name']));
            try {
                kop_facility_save($out, $opts);
            } catch (RuntimeException $e) {
                throw new RuntimeException(preg_replace('/^kop_facility_save: /', 'Not saved, the record does not check out: ', $e->getMessage()));
            }
            $saved = kop_eras_ed_stored($fid, $opts)['doc'];
            $log['doc'] = array(
                'past_before' => $past_before, 'past_after' => array_values((array) ($saved['identification']['pastNames'] ?? array())),
                'merged_before' => $merged_before, 'merged_after' => (array) ($saved['legacy']['mergedFacilities'] ?? array()),
                'popular_before' => $popular_before, 'popular_after' => (string) ($saved['identification']['popularName'] ?? ''),
            );
        }

        foreach ($era_changes as $c) {
            $before = kop_name_eras_row($c['owner'], $c['key']);
            $after = $c['after'];
            if (kop_name_eras_same($before, $after)) continue;
            kop_name_eras_put($c['owner'], $c['key'], $after);
            $log['eras'][] = array('owner' => $c['owner'], 'key' => $c['key'], 'before' => $before, 'after' => kop_name_eras_row($c['owner'], $c['key']));
            $owners[$c['owner']] = true;
        }

        // Names the map joins: each rename line's year, and its direction.
        $log['map'] = kop_eras_ed_map_decisions($final, $pos, $ordered, $cut_between);
    });

    if (!$log['eras'] && !$log['doc'] && !$log['map']) {
        return array('message' => 'Nothing to change.');
    }
    kop_eras_ed_refresh(array_keys($owners));
    $token = wp_generate_password(16, false);
    $all = get_option('kop_eras_editor_log', array());
    if (!is_array($all)) $all = array();
    $all[$token] = $log;
    update_option('kop_eras_editor_log', array_slice($all, -50, null, true), false);
    $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '';
    return array('message' => 'Names and eras saved.', 'redirect' => $url, 'undo' => 'erasundo:' . $token);
}

/**
 * Map Renames decisions for the rename lines between the rows' names: saved
 * with the year the later name came into use, swapped when the years put the
 * line's names the other way round. Lines whose names have no years are left
 * alone. Returns key => {before, after} for the decisions it changed.
 */
function kop_eras_ed_map_decisions(array $final, array $pos, array $ordered, callable $cut_between) {
    if (!function_exists('kop_network_map_graph') || !function_exists('kop_network_renames_list')) return array();
    $graph = kop_network_map_graph();
    if (!$graph) return array();
    $node_key = array();
    foreach ($final as $f) {
        if ($f['was'] && $f['was']['node'] !== '') $node_key[$f['was']['node']] = $f['sent']['key'];
    }
    if (count($node_key) < 2) return array();
    $decisions = kop_network_renames_decisions();
    $changes = array();
    foreach (kop_network_renames_list($graph) as $line) {
        $a = (string) $line['earlier']['id'];
        $b = (string) $line['later']['id'];
        if (!isset($node_key[$a], $node_key[$b])) continue;
        $pa = $pos[$node_key[$a]];
        $pb = $pos[$node_key[$b]];
        $sa = $ordered[$pa];
        $sb = $ordered[$pb];
        $dated_a = (bool) ($sa['from'] || $sa['to']);
        $dated_b = (bool) ($sb['from'] || $sb['to']);
        // The rows say nothing about this line: leave it as it is.
        if (!$dated_a && !$dated_b) continue;
        $before = $decisions[$line['key']] ?? null;
        $was_swapped = $before && ($before['decision'] ?? '') === 'saved' && !empty($before['swapped']);
        // Only the years of both names can turn a line round; with one dated, it keeps its direction.
        $swapped = ($dated_a && $dated_b) ? $pa > $pb : $was_swapped;
        list($earlier, $later) = $swapped ? array($sb, $sa) : array($sa, $sb);
        $year = abs($pa - $pb) === 1 ? $cut_between($earlier, $later) : 0;
        if ($before && ($before['decision'] ?? '') === 'saved' && (int) $before['year'] === $year && !empty($before['swapped']) === $swapped) continue;
        if (!$year && $before && ($before['decision'] ?? '') === 'saved' && !empty($before['swapped']) === $swapped) continue;
        $user = wp_get_current_user();
        $decisions[$line['key']] = array('decision' => 'saved', 'year' => $year, 'swapped' => $swapped,
            'by' => $user ? (string) $user->user_login : '', 'at' => gmdate('Y-m-d H:i:s'));
        $changes[$line['key']] = array('before' => $before, 'after' => $decisions[$line['key']]);
    }
    if ($changes) {
        update_option('kop_network_rename_review', $decisions, false);
        if (function_exists('kop_facility_eras_map')) kop_facility_eras_map(true);
    }
    return $changes;
}

/** Fresh pages and lists after a save or undo: the facility pages' index and each touched record's page. */
function kop_eras_ed_refresh(array $fids) {
    if (function_exists('kop_name_eras_all')) kop_name_eras_all(true);
    if (function_exists('kop_facility_eras_map')) kop_facility_eras_map(true);
    if (function_exists('kop_facility_pages_flush_index')) kop_facility_pages_flush_index();
    foreach ($fids as $id) {
        $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $id) : '';
        kop_ie_purge_url($url);
    }
}

function kop_eras_ed_undo_load(array $p) {
    throw new RuntimeException('This saves at once; there is nothing to open.');
}

/** Puts back everything one save wrote, unless any of it has changed since. */
function kop_eras_ed_undo_save(array $p, array $v) {
    $token = (string) ($p[0] ?? '');
    $all = get_option('kop_eras_editor_log', array());
    $log = is_array($all) ? ($all[$token] ?? null) : null;
    if (!is_array($log)) {
        throw new RuntimeException('There is nothing to undo.');
    }
    $fid = (int) $log['fid'];
    $changed = 'It has been changed since; set it again with the "Names and eras" pencil.';
    foreach ($log['eras'] as $e) {
        if (!kop_name_eras_same(kop_name_eras_row($e['owner'], $e['key']), $e['after'])) throw new RuntimeException($changed);
    }
    $decisions = function_exists('kop_network_renames_decisions') ? kop_network_renames_decisions() : array();
    foreach ($log['map'] as $key => $m) {
        if (($decisions[$key] ?? null) != $m['after']) throw new RuntimeException($changed);
    }
    if ($log['doc']) {
        $opts = kop_ie_facility_opts($fid);
        kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $opts, $log, $changed) {
            $stored = kop_eras_ed_stored($fid, $opts);
            $doc = $stored['doc'];
            $d = $log['doc'];
            if (array_values((array) ($doc['identification']['pastNames'] ?? array())) != $d['past_after']
                || (array) ($doc['legacy']['mergedFacilities'] ?? array()) != $d['merged_after']
                || (isset($d['popular_after']) && (string) ($doc['identification']['popularName'] ?? '') !== $d['popular_after'])) {
                throw new RuntimeException($changed);
            }
            $doc['identification']['pastNames'] = $d['past_before'];
            if (isset($d['popular_before'])) $doc['identification']['popularName'] = $d['popular_before'];
            if ($d['merged_before']) {
                $doc['legacy']['mergedFacilities'] = $d['merged_before'];
            } else {
                unset($doc['legacy']['mergedFacilities']);
            }
            kop_facility_save(kop_facility_normalize($doc, array('facility_id' => $doc['facility_id'] ?? $fid, 'unique_name' => $stored['unique_name'])), $opts);
        });
    }
    $owners = array($fid => true);
    foreach (array_reverse($log['eras']) as $e) {
        kop_name_eras_put($e['owner'], $e['key'], $e['before']);
        $owners[(int) $e['owner']] = true;
    }
    if ($log['map']) {
        foreach ($log['map'] as $key => $m) {
            if ($m['before'] === null) unset($decisions[$key]);
            else $decisions[$key] = $m['before'];
        }
        update_option('kop_network_rename_review', $decisions, false);
    }
    unset($all[$token]);
    update_option('kop_eras_editor_log', $all, false);
    kop_eras_ed_refresh(array_keys($owners));
    $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '';
    return array('message' => 'Undone.', 'redirect' => $url);
}
