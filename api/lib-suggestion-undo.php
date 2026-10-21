<?php
/**
 * Exact Undo for an approved data suggestion (suggested_edits).
 *
 * Approve (kop_apply_suggested_edit()) takes a copy of everything its v2 save
 * can write before it writes: each facility it may touch (by facility_id or by
 * the same name), their location and company links, the company row it files
 * under, the state's profile row. Undo puts exactly that back: the facilities'
 * earlier documents, facilities and a company the approval created deleted,
 * links it added removed, and the suggestion pending again. A facility or
 * company changed since the approval is never written over: Undo refuses and
 * names it.
 *
 *   kop_sugg_undo_snapshot()  before the save
 *   kop_sugg_undo_finish()    after it: what was created, what each record now reads
 *   kop_sugg_undo_store()     after commit (option kop_sugg_undo_<id>)
 *   kop_undo_suggested_edit() the Undo
 */

if (!function_exists('kop_sugg_undo_rows')) {
    function kop_sugg_undo_rows(PDO $pdo, $sql, array $args) {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('kop_sugg_undo_in')) {
    /** "(?, ?, ?)" for a non-empty id list, "(0)" for an empty one. */
    function kop_sugg_undo_in(array $ids) {
        return $ids ? '(' . implode(',', array_fill(0, count($ids), '?')) . ')' : '(0)';
    }
}

if (!function_exists('kop_sugg_undo_snapshot')) {
    /**
     * $operator: the company project name the save files under, or null.
     * $location: the location key (state) the save files under, or null.
     */
    function kop_sugg_undo_snapshot(PDO $pdo, $prefix, array $facilities, $operator, $location) {
        $t = kop_migration_tables($prefix);
        $opts = array('pdo' => $pdo, 'prefix' => $prefix);
        $ids = array();
        foreach ($facilities as $f) {
            if (!is_array($f)) continue;
            if ((int) ($f['facility_id'] ?? 0) > 0) $ids[] = (int) $f['facility_id'];
            // The save may match an entry without an id to a record of the same name (kop_facility_resolve_identity()).
            $key = kop_facility_name_key((string) ($f['identification']['name'] ?? ''));
            if ($key === '') continue;
            $rows = kop_facility_has_generated_columns($opts)
                ? kop_sugg_undo_rows($pdo, "SELECT id FROM `{$t['facilities']}` WHERE name_key = ?", array($key))
                : kop_sugg_undo_rows($pdo, "SELECT id FROM `{$t['facilities']}` WHERE JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.identification.nameKey')) = ?", array($key));
            foreach ($rows as $r) $ids[] = (int) $r['id'];
        }
        $ids = array_values(array_unique($ids));

        $snap = array(
            'operator_name' => $operator, 'location' => $location,
            'all_ids' => array_map('intval', $pdo->query("SELECT id FROM `{$t['facilities']}`")->fetchAll(PDO::FETCH_COLUMN)),
            'facilities' => array(),
            'operator' => null,
        );
        foreach (kop_sugg_undo_rows($pdo, "SELECT id, json_data FROM `{$t['facilities']}` WHERE id IN " . kop_sugg_undo_in($ids), $ids) as $r) {
            $snap['facilities'][(int) $r['id']] = (string) $r['json_data'];
        }
        if ($operator !== null) {
            $op = kop_sugg_undo_rows($pdo, "SELECT id, name, json_data, document_folder_id FROM `{$t['operators']}` WHERE unique_name = ?", array($operator));
            $snap['operator'] = $op ? $op[0] : null;
        }
        $snap['profile'] = null;
        if ($location !== null) {
            $p = kop_sugg_undo_rows($pdo, "SELECT id, json_data FROM locations_master WHERE UPPER(TRIM(unique_name)) = ? ORDER BY id LIMIT 1", array($location));
            $snap['profile'] = $p ? $p[0] : null;
        }
        $snap['links_ids'] = array_fill_keys($ids, true);
        $snap['links'] = kop_sugg_undo_links($pdo, $t, $ids, $snap['operator'] ? (int) $snap['operator']['id'] : 0, $location);
        return $snap;
    }
}

if (!function_exists('kop_sugg_undo_sig')) {
    /** A link row by what it says (its own id aside). */
    function kop_sugg_undo_sig(array $row) {
        unset($row['id']);
        ksort($row);
        return json_encode(array_map(function ($v) { return $v === null ? null : (string) $v; }, $row));
    }
}

if (!function_exists('kop_sugg_undo_links')) {
    /** The location and company links in scope: rows of these facilities, of this company, of this location. */
    function kop_sugg_undo_links(PDO $pdo, array $t, array $ids, $operator_id, $location) {
        $loc_where = 'facility_id IN ' . kop_sugg_undo_in($ids) . ($location !== null ? ' OR location_key = ?' : '');
        $loc_args = $location !== null ? array_merge($ids, array($location)) : $ids;
        $op_where = 'facility_id IN ' . kop_sugg_undo_in($ids) . ($operator_id ? ' OR operator_id = ?' : '');
        $op_args = $operator_id ? array_merge($ids, array($operator_id)) : $ids;
        return array(
            'locations' => kop_sugg_undo_rows($pdo, "SELECT * FROM `{$t['facility_locations']}` WHERE $loc_where", $loc_args),
            'operators' => kop_sugg_undo_rows($pdo, "SELECT * FROM `{$t['operator_facilities']}` WHERE $op_where", $op_args),
        );
    }
}

if (!function_exists('kop_sugg_undo_finish')) {
    /** After the save: the facilities and company it created, and what each touched record reads now. */
    function kop_sugg_undo_finish(PDO $pdo, $prefix, array $snap) {
        $t = kop_migration_tables($prefix);
        $now_ids = array_map('intval', $pdo->query("SELECT id FROM `{$t['facilities']}`")->fetchAll(PDO::FETCH_COLUMN));
        $snap['created'] = array_values(array_diff($now_ids, $snap['all_ids']));
        unset($snap['all_ids']);
        $ids = array_merge(array_keys($snap['facilities']), $snap['created']);
        $snap['after'] = array();
        foreach (kop_sugg_undo_rows($pdo, "SELECT id, json_data FROM `{$t['facilities']}` WHERE id IN " . kop_sugg_undo_in($ids), $ids) as $r) {
            $snap['after'][(int) $r['id']] = (string) $r['json_data'];
        }
        $snap['operator_created'] = null;
        $snap['operator_after'] = null;
        if ($snap['operator_name'] !== null) {
            $op = kop_sugg_undo_rows($pdo, "SELECT id, json_data FROM `{$t['operators']}` WHERE unique_name = ?", array($snap['operator_name']));
            if ($op && !$snap['operator']) $snap['operator_created'] = (int) $op[0]['id'];
            $snap['operator_after'] = $op ? (string) $op[0]['json_data'] : null;
        }
        // Only what the approval changed: same-name facilities it left alone are not Undo's business.
        foreach ($snap['facilities'] as $fid => $json) {
            if (isset($snap['after'][$fid]) && kop_sugg_undo_same($snap['after'][$fid], $json)) {
                unset($snap['facilities'][$fid], $snap['after'][$fid]);
            }
        }
        // Links: the rows the approval added and the rows it took away, nothing else.
        $op_id = $snap['operator'] ? (int) $snap['operator']['id'] : (int) $snap['operator_created'];
        $after_links = kop_sugg_undo_links($pdo, $t, array_merge(array_keys($snap['links_ids']), $snap['created']), $op_id, $snap['location']);
        $snap['links_added'] = array();
        $snap['links_removed'] = array();
        foreach (array('locations', 'operators') as $k) {
            $before = array();
            foreach ($snap['links'][$k] as $r) $before[kop_sugg_undo_sig($r)] = $r;
            $after = array();
            foreach ($after_links[$k] as $r) $after[kop_sugg_undo_sig($r)] = $r;
            $snap['links_added'][$k] = array_values(array_diff_key($after, $before));
            $snap['links_removed'][$k] = array_values(array_diff_key($before, $after));
        }
        unset($snap['links'], $snap['links_ids']);
        // The company row and the state profile only when the approval changed them.
        $snap['operator_changed'] = $snap['operator'] && $snap['operator_after'] !== null
            && !kop_sugg_undo_same($snap['operator']['json_data'], $snap['operator_after']);
        if (!$snap['operator_created'] && !$snap['operator_changed']) $snap['operator_after'] = null;
        $snap['profile_created'] = null;
        $snap['profile_after'] = null;
        if ($snap['location'] !== null) {
            $p = kop_sugg_undo_rows($pdo, "SELECT id, json_data FROM locations_master WHERE UPPER(TRIM(unique_name)) = ? ORDER BY id LIMIT 1", array($snap['location']));
            if ($p && !$snap['profile']) $snap['profile_created'] = (int) $p[0]['id'];
            if ($p && $snap['profile'] && kop_sugg_undo_same($p[0]['json_data'], $snap['profile']['json_data'])) $snap['profile'] = null;
            if ($p && ($snap['profile'] || $snap['profile_created'])) $snap['profile_after'] = (string) $p[0]['json_data'];
        }
        return $snap;
    }
}

if (!function_exists('kop_sugg_undo_store')) {
    function kop_sugg_undo_store($id, $snap) {
        if (function_exists('update_option')) update_option('kop_sugg_undo_' . (int) $id, $snap, false);
    }
}

if (!function_exists('kop_sugg_undo_same')) {
    function kop_sugg_undo_same($a, $b) {
        return kop_facility_same_document(json_decode((string) $a, true), json_decode((string) $b, true));
    }
}

if (!function_exists('kop_undo_suggested_edit')) {
    /**
     * Undo an approval: everything it wrote put back, the suggestion pending
     * again. Returns ['success', 'message', 'error'].
     */
    function kop_undo_suggested_edit(PDO $pdo, $id, $prefix = '') {
        $id = (int) $id;
        $snap = function_exists('get_option') ? get_option('kop_sugg_undo_' . $id, null) : null;
        if (!is_array($snap) || !isset($snap['after'])) {
            return array('success' => false, 'message' => '', 'error' => 'This suggestion was approved before Approve kept a copy of what it changed, so it cannot be undone here; fix the record by hand.');
        }
        $t = kop_migration_tables($prefix);
        $opts = array('pdo' => $pdo, 'prefix' => $prefix);
        $name = function ($fid) use ($snap) {
            $d = json_decode((string) ($snap['after'][$fid] ?? ''), true);
            return (string) ($d['identification']['name'] ?? ('facility ' . $fid));
        };

        // Changed since: never written over.
        $ids = array_keys($snap['after']);
        $now = array();
        foreach (kop_sugg_undo_rows($pdo, "SELECT id, json_data FROM `{$t['facilities']}` WHERE id IN " . kop_sugg_undo_in($ids), $ids) as $r) {
            $now[(int) $r['id']] = (string) $r['json_data'];
        }
        $changed = array();
        foreach ($snap['after'] as $fid => $json) {
            if (!isset($now[$fid]) || !kop_sugg_undo_same($now[$fid], $json)) $changed[] = $name($fid);
        }
        if ($snap['operator_name'] !== null && $snap['operator_after'] !== null) {
            $op = kop_sugg_undo_rows($pdo, "SELECT json_data FROM `{$t['operators']}` WHERE unique_name = ?", array($snap['operator_name']));
            if (!$op || !kop_sugg_undo_same($op[0]['json_data'], $snap['operator_after'])) $changed[] = 'the company ' . $snap['operator_name'];
        }
        if ($snap['profile_after'] !== null) {
            $p = kop_sugg_undo_rows($pdo, "SELECT json_data FROM locations_master WHERE UPPER(TRIM(unique_name)) = ? ORDER BY id LIMIT 1", array($snap['location']));
            if (!$p || !kop_sugg_undo_same($p[0]['json_data'], $snap['profile_after'])) $changed[] = 'the ' . $snap['location'] . ' page data';
        }
        if ($changed) {
            return array('success' => false, 'message' => '', 'error' => 'Changed since this approval, so Undo would write over that work: ' . implode(', ', $changed) . '. Fix it by hand instead.');
        }

        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $created = array_map('intval', $snap['created']);

            // Links: take back the rows the approval added, put back the rows it took away.
            $op_before = $snap['operator'] ? (int) $snap['operator']['id'] : 0;
            $op_scope = $op_before ?: (int) $snap['operator_created'];
            foreach (array('facility_locations' => 'locations', 'operator_facilities' => 'operators') as $table => $k) {
                foreach ($snap['links_added'][$k] as $row) {
                    unset($row['id']);
                    $where = array();
                    $args = array();
                    foreach ($row as $c => $v) {
                        if ($v === null) { $where[] = "`$c` IS NULL"; continue; }
                        $where[] = "`$c` = ?";
                        $args[] = $v;
                    }
                    $pdo->prepare("DELETE FROM `{$t[$table]}` WHERE " . implode(' AND ', $where))->execute($args);
                }
                foreach ($snap['links_removed'][$k] as $row) {
                    unset($row['id']);
                    $cols = array_keys($row);
                    $pdo->prepare("INSERT INTO `{$t[$table]}` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ')')
                        ->execute(array_values($row));
                }
            }
            if ($created) {
                // Anything else that came to point at a facility the approval made.
                $pdo->prepare("DELETE FROM `{$t['facility_locations']}` WHERE facility_id IN " . kop_sugg_undo_in($created))->execute($created);
                $pdo->prepare("DELETE FROM `{$t['operator_facilities']}` WHERE facility_id IN " . kop_sugg_undo_in($created))->execute($created);
            }

            $up = $pdo->prepare("UPDATE `{$t['facilities']}` SET json_data = ? WHERE id = ?");
            foreach ($snap['facilities'] as $fid => $json) $up->execute(array($json, (int) $fid));
            if ($created) $pdo->prepare("DELETE FROM `{$t['facilities']}` WHERE id IN " . kop_sugg_undo_in($created))->execute($created);

            if ($snap['operator'] && $snap['operator_changed']) {
                $pdo->prepare("UPDATE `{$t['operators']}` SET name = ?, json_data = ?, document_folder_id = ? WHERE id = ?")
                    ->execute(array($snap['operator']['name'], $snap['operator']['json_data'], $snap['operator']['document_folder_id'], $op_before));
            } elseif ($snap['operator_created']) {
                $pdo->prepare("DELETE FROM `{$t['operators']}` WHERE id = ?")->execute(array((int) $snap['operator_created']));
            }
            if ($snap['profile']) {
                $pdo->prepare('UPDATE locations_master SET json_data = ? WHERE id = ?')->execute(array($snap['profile']['json_data'], (int) $snap['profile']['id']));
            } elseif ($snap['profile_created']) {
                $pdo->prepare('DELETE FROM locations_master WHERE id = ?')->execute(array((int) $snap['profile_created']));
            }

            $pdo->prepare("UPDATE `" . kop_resolve_table_name($pdo, 'suggested_edits', $prefix) . "` SET status = 'pending' WHERE id = ?")->execute(array($id));
            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            return array('success' => false, 'message' => '', 'error' => 'Undo failed, nothing was changed: ' . $e->getMessage());
        }

        if (function_exists('delete_option')) delete_option('kop_sugg_undo_' . $id);
        elseif (function_exists('update_option')) update_option('kop_sugg_undo_' . $id, null);
        if (function_exists('kop_record_changed')) {
            kop_record_changed('facility', array_merge(array_keys($snap['facilities']), $snap['created']));
            if ($op_scope) kop_record_changed('operator', array($op_scope));
        }
        $parts = array();
        if ($snap['facilities']) $parts[] = count($snap['facilities']) . ' record' . (count($snap['facilities']) === 1 ? '' : 's') . ' put back';
        if ($snap['created']) $parts[] = count($snap['created']) . ' new facilit' . (count($snap['created']) === 1 ? 'y' : 'ies') . ' removed';
        if ($snap['operator_created']) $parts[] = 'the company it made removed';
        return array('success' => true, 'message' => 'Undone: ' . ($parts ? implode(', ', $parts) : 'nothing had changed') . '. The suggestion is waiting again.', 'error' => null);
    }
}
