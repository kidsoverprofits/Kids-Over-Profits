<?php
/**
 * One-time corrections to existing lawsuit rows (seeds/lawsuit-updates.json).
 *
 * kop_apply_lawsuit_seeds() only inserts new cases and runs with the template
 * assignments, so a case's later status (settled, dismissed, a new ruling) lands
 * here instead: each update matches a row by id AND its current case_name, sets
 * only the fields it lists, adds 'add_source_urls' to the row's source_urls,
 * moves the case's facility links listed in 'move_facility_links' and
 * appends its note to reviewer_notes. The whole file runs once per 'version';
 * new cases in seeds/lawsuits.json are inserted in the same pass and linked to
 * their facilities by name.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/choice-lists.php';   // lawsuit and bill statuses (KOP Tools > Dropdown Lists)
require_once __DIR__ . '/lawsuit-defendant-types.php';   // defendant_types column and its list

/**
 * A docket number reduced to what identifies the case in its court: "1:21-cv-00848-RJJ-RSK",
 * "1:2021cv00848" and "21-cv-848" all give "21-cv-00848"; a state number ("200500204",
 * "25CV017771-910") gives its letters and digits only. '' for nothing.
 */
function kop_lawsuit_docket_key($number) {
    $number = strtolower(trim((string) $number));
    if ($number === '') return '';
    if (preg_match('/(?:\d{1,2}:)?(?:19|20)?(\d{2})[-\s]?(cv|cr|mc|md|bk|ap|mj)[-\s]?(\d{1,6})/', $number, $m)) {
        return $m[1] . '-' . $m[2] . '-' . str_pad(ltrim($m[3], '0') ?: '0', 5, '0', STR_PAD_LEFT);
    }
    return preg_replace('/[^a-z0-9]+/', '', $number);
}

/**
 * Lawsuit rows whose docket number has $docket's key and whose case_name holds every word
 * in $name_contains (case-insensitive), published ones only when $published_only. Whole rows, by id.
 */
function kop_lawsuit_rows_by_docket(PDO $pdo, $docket, $name_contains = '', $published_only = false) {
    $key = kop_lawsuit_docket_key($docket);
    if ($key === '') return array();
    $digits = preg_replace('/\D+/', '', substr($key, -5));
    $like = '%' . ltrim($digits, '0') . '%';
    $st = $pdo->prepare('SELECT * FROM lawsuits WHERE case_number LIKE ?'
        . ($published_only ? " AND publication_status IN ('approved','published')" : '') . ' ORDER BY id');
    $st->execute(array($like));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (kop_lawsuit_docket_key($row['case_number']) !== $key) continue;
        $ok = true;
        foreach (preg_split('/\s+/', trim((string) $name_contains)) as $w) {
            if ($w !== '' && stripos((string) $row['case_name'], $w) === false) $ok = false;
        }
        if ($ok) $out[] = $row;
    }
    return $out;
}

/** Rows whose caption holds every word of $name_contains and whose filing date is $date (Y-m-d). */
function kop_lawsuit_rows_by_name_date(PDO $pdo, $name_contains, $date) {
    $words = array_filter(preg_split('/\s+/', trim((string) $name_contains)));
    if (!$words || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) return array();
    $st = $pdo->prepare('SELECT * FROM lawsuits WHERE filing_date LIKE ? ORDER BY id');
    $st->execute(array($date . '%'));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ok = true;
        foreach ($words as $w) {
            if (stripos((string) $row['case_name'], $w) === false) $ok = false;
        }
        if ($ok) $out[] = $row;
    }
    return $out;
}

/**
 * Merge second copies of one case ('merge_duplicates' in seeds/lawsuit-updates.json):
 * each entry names the docket, a word of the caption, the filing date and the court's
 * district word, and how many rows it expects. Only when exactly that many rows match
 * (same docket key, same filing date, the district word in every court) is anything
 * done. The row with the court's full docket number (the longest) is kept; it takes the
 * others' source, document and tag links, claims and defendant types, and any field it
 * has empty; their facility and news links move to it; the copies are deleted, each one
 * saved whole in the option kop_lawsuit_merges_log first. A 'keep_status' entry sets the
 * kept row's status (the copies disagreed).
 */
function kop_apply_lawsuit_merges(PDO $pdo, array $spec, array &$done) {
    $lists = array('source_urls', 'document_urls', 'tags', 'claims');
    $fill_lists = array('plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned');
    $scalars = array('court', 'jurisdiction', 'outcome', 'settlement_amount', 'summary', 'filebird_folder_id');
    $has_dtypes = kop_lawsuits_defendant_types_ready($pdo);
    $log = function_exists('get_option') ? (array) get_option('kop_lawsuit_merges_log', array()) : array();
    $enc = static function ($v) {
        return wp_json_encode(array_values($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $dec = static function ($v) {
        $d = json_decode((string) $v, true);
        return is_array($d) ? $d : array();
    };
    foreach ((array) ($spec['merge_duplicates'] ?? array()) as $m) {
        $rows = kop_lawsuit_rows_by_docket($pdo, (string) ($m['docket'] ?? ''), (string) ($m['name_contains'] ?? ''), true);
        $label = (string) ($m['docket'] ?? '');
        if (count($rows) !== (int) ($m['expect'] ?? 2)) {
            $done['merge_skipped'][] = $label . ': ' . count($rows) . ' rows';
            continue;
        }
        $same = true;
        foreach ($rows as $r) {
            if ((string) ($r['filing_date'] ?? '') !== (string) ($m['filing_date'] ?? '')) $same = false;
            if (!empty($m['court_word']) && stripos((string) $r['court'], (string) $m['court_word']) === false) $same = false;
        }
        if (!$same) {
            $done['merge_skipped'][] = $label . ': filing date or court differ';
            continue;
        }
        usort($rows, static function ($a, $b) {
            $l = strlen((string) $b['case_number']) - strlen((string) $a['case_number']);
            return $l !== 0 ? $l : ((int) $a['id'] - (int) $b['id']);
        });
        $keep = array_shift($rows);
        $keep_id = (int) $keep['id'];
        $set = array();
        $args = array();
        $merged = array();
        foreach ($lists as $col) {
            $all = $dec($keep[$col]);
            foreach ($rows as $r) $all = array_merge($all, $dec($r[$col]));
            $all = array_values(array_unique(array_map('strval', $all)));
            if ($all !== array_map('strval', $dec($keep[$col]))) {
                $set[] = "`{$col}` = ?";
                $args[] = $enc($all);
            }
        }
        foreach ($fill_lists as $col) {
            if ($dec($keep[$col])) continue;
            foreach ($rows as $r) {
                if ($dec($r[$col])) {
                    $set[] = "`{$col}` = ?";
                    $args[] = (string) $r[$col];
                    break;
                }
            }
        }
        foreach ($scalars as $col) {
            if (trim((string) ($keep[$col] ?? '')) !== '') continue;
            foreach ($rows as $r) {
                if (trim((string) ($r[$col] ?? '')) !== '') {
                    $set[] = "`{$col}` = ?";
                    $args[] = $r[$col];
                    break;
                }
            }
        }
        if ($has_dtypes) {
            $types = kop_lawsuit_defendant_types_clean((string) ($keep['defendant_types'] ?? ''));
            foreach ($rows as $r) $types = array_merge($types, kop_lawsuit_defendant_types_clean((string) ($r['defendant_types'] ?? '')));
            $types = kop_lawsuit_defendant_types_clean($types);
            if ($types) {
                $set[] = '`defendant_types` = ?';
                $args[] = wp_json_encode($types);
            }
        }
        if (!empty($m['keep_status']) && in_array($m['keep_status'], kop_lawsuit_status_values('all'), true)) {
            $set[] = '`status` = ?';
            $args[] = $m['keep_status'];
        }
        foreach ($rows as $r) {
            $merged[] = '#' . (int) $r['id'] . ' "' . $r['case_name'] . '" (' . $r['case_number'] . ', status ' . $r['status'] . ')';
        }
        $set[] = "reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), ?)";
        $args[] = "\n[claude " . (string) ($spec['version'] ?? '') . '] Merged duplicate ' . implode('; ', $merged)
            . ' into this case. ' . (string) ($m['note'] ?? '');
        $pdo->beginTransaction();
        try {
            $args[] = $keep_id;
            $pdo->prepare('UPDATE lawsuits SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
            foreach ($rows as $r) {
                $drop = (int) $r['id'];
                foreach (array('lawsuit_facility_links', 'lawsuit_news_links') as $t) {
                    try {
                        $pdo->prepare("UPDATE IGNORE {$t} SET lawsuit_id = ? WHERE lawsuit_id = ?")->execute(array($keep_id, $drop));
                    } catch (Throwable $e) {
                        // table not on this database
                    }
                }
                $log[] = array('at' => gmdate('c'), 'kept' => $keep_id, 'row' => $r);
                $pdo->prepare('DELETE FROM lawsuits WHERE id = ?')->execute(array($drop));
                $done['merged'][] = $drop . '->' . $keep_id;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('kop_apply_lawsuit_merges ' . $label . ': ' . $e->getMessage());
            $done['merge_skipped'][] = $label . ': ' . $e->getMessage();
            continue;
        }
        if (function_exists('update_option')) update_option('kop_lawsuit_merges_log', $log, false);
        require_once get_stylesheet_directory() . '/api/lib-record-resync.php';
        kop_records_deleted($pdo, 'lawsuit', array_map(static function ($r) { return (int) $r['id']; }, $rows));
        $done['updated'][] = $keep_id;
    }
}

function kop_apply_lawsuit_updates() {
    $done = array('updated' => array(), 'skipped' => array(), 'inserted' => array());
    $path = trailingslashit(get_stylesheet_directory()) . 'seeds/lawsuit-updates.json';
    $spec = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($spec) || empty($spec['updates']) || !is_array($spec['updates'])) {
        return $done;
    }
    $pdo = kop_seed_pdo();
    if (!$pdo) {
        return null;
    }

    $done['inserted'] = kop_apply_lawsuit_seeds();
    $new_ids = array_values(array_filter($done['inserted'], 'is_int'));
    if ($new_ids) {
        $lib = get_stylesheet_directory() . '/api/lawsuit-facility-links.php';
        if (file_exists($lib)) {
            require_once $lib;
        }
        if (function_exists('kop_sync_lawsuit_facility_links')) {
            $mentions = $pdo->prepare('SELECT facilities_mentioned FROM lawsuits WHERE id = ?');
            foreach ($new_ids as $new_id) {
                try {
                    $mentions->execute(array($new_id));
                    kop_sync_lawsuit_facility_links($pdo, $new_id, (string) $mentions->fetchColumn(), 'seed');
                } catch (Throwable $e) {
                    error_log('kop_apply_lawsuit_updates link ' . $new_id . ': ' . $e->getMessage());
                }
            }
        }
    }

    $columns = array(
        'case_name', 'case_number', 'court', 'jurisdiction', 'filing_date', 'status', 'plaintiffs', 'defendants',
        'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned', 'claims', 'outcome', 'settlement_amount',
        'summary', 'source_urls', 'document_urls', 'tags', 'publication_status',
    );
    if (kop_lawsuits_defendant_types_ready($pdo)) {
        $columns[] = 'defendant_types';
    }
    $lists = array('plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned',
        'claims', 'source_urls', 'document_urls', 'tags');
    $statuses = kop_lawsuit_status_values('all');   // KOP Tools > Dropdown Lists
    $encode = static function ($v) {
        return wp_json_encode(is_array($v) ? array_values($v) : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    try {
        $read = $pdo->prepare('SELECT source_urls FROM lawsuits WHERE id = ? AND case_name = ?');
        foreach ($spec['updates'] as $u) {
            // 'match': {docket, name_contains} finds the row when its id is not known; only a lone match counts.
            $was = '';
            // A row with no docket number on file is found by {name_contains, filing_date} instead.
            if (empty($u['id']) && (!empty($u['match']['docket']) || (!empty($u['match']['name_contains']) && !empty($u['match']['filing_date'])))) {
                $found = !empty($u['match']['docket'])
                    ? kop_lawsuit_rows_by_docket($pdo, (string) $u['match']['docket'], (string) ($u['match']['name_contains'] ?? ''))
                    : array();
                if (!$found && !empty($u['match']['name_contains']) && !empty($u['match']['filing_date'])) {
                    $found = kop_lawsuit_rows_by_name_date($pdo, (string) $u['match']['name_contains'], (string) $u['match']['filing_date']);
                }
                if (count($found) !== 1) {
                    $done['skipped'][] = 'match ' . ($u['match']['docket'] ?? $u['match']['name_contains']) . ': ' . count($found) . ' rows';
                    continue;
                }
                $u['id'] = (int) $found[0]['id'];
                $u['case_name'] = (string) $found[0]['case_name'];
                // What the update replaces goes into the note, so it can be put back by hand.
                foreach (array_keys((array) ($u['set'] ?? array())) as $col) {
                    if (array_key_exists($col, $found[0]) && trim((string) $found[0][$col]) !== '') {
                        $was .= ' Was ' . $col . ': ' . $found[0][$col] . '.';
                    }
                }
            }
            $id = (int) ($u['id'] ?? 0);
            $name = (string) ($u['case_name'] ?? '');
            if ($id <= 0 || $name === '') {
                continue;
            }
            $read->execute(array($id, $name));
            $current_sources = $read->fetchColumn();
            if ($current_sources === false) {
                $done['skipped'][] = $id;
                continue;
            }
            $set = array();
            $args = array();
            foreach ((array) ($u['set'] ?? array()) as $col => $val) {
                if (!in_array($col, $columns, true)) {
                    continue;
                }
                if ($col === 'publication_status' && !in_array($val, array('draft', 'pending', 'approved', 'published', 'rejected'), true)) {
                    continue;
                }
                if ($col === 'status' && !in_array($val, $statuses, true)) {
                    continue;
                }
                if ($col === 'defendant_types') {
                    $set[] = '`defendant_types` = ?';
                    $args[] = wp_json_encode(kop_lawsuit_defendant_types_clean((array) $val));
                    continue;
                }
                if (in_array($col, $lists, true)) {
                    $val = $encode($val);
                }
                $set[] = "`{$col}` = ?";
                $args[] = ($val === '' && $col === 'filing_date') ? null : $val;
            }
            if (!empty($u['add_source_urls']) && is_array($u['add_source_urls']) && !isset($u['set']['source_urls'])) {
                $have = json_decode((string) $current_sources, true);
                $have = is_array($have) ? $have : array();
                $merged = array_values(array_unique(array_merge($have, array_values($u['add_source_urls']))));
                $set[] = '`source_urls` = ?';
                $args[] = $encode($merged);
            }
            // 'move_facility_links': [{from, to}] moves the case's link off a record that is gone (or wrong)
            // onto the live one, keeping its link_type; never onto an id facilities_v2 does not hold.
            $moved = 0;
            foreach ((array) ($u['move_facility_links'] ?? array()) as $move) {
                $from = (int) ($move['from'] ?? 0);
                $to = (int) ($move['to'] ?? 0);
                if ($from <= 0 || $to <= 0 || $from === $to) {
                    continue;
                }
                $has_to = $pdo->prepare('SELECT 1 FROM facilities_v2 WHERE id = ?');
                $has_to->execute(array($to));
                if (!$has_to->fetchColumn()) {
                    continue;
                }
                $pdo->prepare('UPDATE IGNORE lawsuit_facility_links SET facility_id = ? WHERE lawsuit_id = ? AND facility_id = ?')
                    ->execute(array($to, $id, $from));
                $drop = $pdo->prepare('DELETE FROM lawsuit_facility_links WHERE lawsuit_id = ? AND facility_id = ?');   // left when the case was already on $to
                $drop->execute(array($id, $from));
                $moved++;
            }
            if (!$set && !$moved) {
                continue;
            }
            // A note already on the row (the same update run again under a later version) is not added twice.
            $notes_now = $pdo->prepare('SELECT reviewer_notes FROM lawsuits WHERE id = ?');
            $notes_now->execute(array($id));
            if (!empty($u['note']) && strpos((string) $notes_now->fetchColumn(), (string) $u['note']) === false) {
                $set[] = "reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), ?)";
                $args[] = "\n[claude " . (string) ($spec['version'] ?? '') . '] ' . (string) $u['note'] . $was;
            }
            if (!$set) {
                $done['updated'][] = $id;
                continue;
            }
            $args[] = $id;
            $args[] = $name;
            $stmt = $pdo->prepare('UPDATE lawsuits SET ' . implode(', ', $set) . ' WHERE id = ? AND case_name = ?');
            $stmt->execute($args);
            if ($stmt->rowCount() || $moved) {
                $done['updated'][] = $id;
            } else {
                $done['skipped'][] = $id;
            }
        }
        kop_apply_lawsuit_merges($pdo, $spec, $done);
    } catch (Throwable $e) {
        error_log('kop_apply_lawsuit_updates: ' . $e->getMessage());
        return null;
    }
    // Corrected cases: facility links and coverage follow the new values.
    if (!empty($done['updated'])) {
        require_once get_stylesheet_directory() . '/api/lib-record-resync.php';
        kop_resync_lawsuit($pdo, $done['updated'], 'seed');
    }
    return $done;
}

/**
 * Published cases whose status and outcome text disagree (an open case whose outcome says it was
 * settled or dismissed, a settled case whose outcome says dismissed, and the reverse), for the
 * "To check" list on the lawsuit editor. Nothing is changed: the court record decides which is right.
 * [ [id, case_name, status, outcome, why], ... ]
 */
function kop_lawsuit_status_outcome_conflicts(PDO $pdo) {
    $out = array();
    try {
        $rows = $pdo->query("SELECT id, case_name, status, outcome FROM lawsuits
            WHERE publication_status IN ('approved','published') AND outcome IS NOT NULL AND outcome <> '' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }
    $open = array('filed', 'in_progress', 'unknown', 'appeal');
    foreach ($rows as $r) {
        $text = strtolower(preg_replace('/\bmotions? to dismiss\b|\bnot (?:been )?(?:dismissed|settled)\b|\bpartial(?:ly)? dismiss\w*|\bdismissed (?:as to|against) (?:some|one|two|several|certain)\b/i', '', (string) $r['outcome']));
        $says_settled = (bool) preg_match('/\bsettle(?:d|ment (?:was )?(?:reached|approved|agreed))\b/', $text);
        $says_dismissed = (bool) preg_match('/\b(?:case|suit|lawsuit|complaint|action)?\s*(?:was )?dismissed\b/', $text);
        $says_decided = (bool) preg_match('/\b(?:jury (?:found|verdict|awarded)|verdict for|judgment (?:for|entered|in favor))\b/', $text);
        $why = '';
        if (in_array($r['status'], $open, true) && ($says_settled || $says_dismissed || $says_decided)) {
            $why = 'Status is open, but the outcome says ' . ($says_settled ? 'settled' : ($says_dismissed ? 'dismissed' : 'decided')) . '.';
        } elseif ($r['status'] === 'settled' && $says_dismissed && !$says_settled) {
            $why = 'Status is settled, but the outcome says dismissed.';
        } elseif ($r['status'] === 'dismissed' && $says_settled && !$says_dismissed) {
            $why = 'Status is dismissed, but the outcome says settled.';
        }
        if ($why !== '') {
            $r['why'] = $why;
            $out[] = $r;
        }
    }
    return $out;
}

function kop_maybe_apply_lawsuit_updates() {
    $path = trailingslashit(get_stylesheet_directory()) . 'seeds/lawsuit-updates.json';
    if (!file_exists($path)) {
        return;
    }
    $spec = json_decode((string) file_get_contents($path), true);
    $version = is_array($spec) ? (string) ($spec['version'] ?? '') : '';
    if ($version === '' || get_option('kop_lawsuit_updates_applied') === $version) {
        return;
    }
    // One request at a time; a failed run (no database) tries again on a later request.
    if (get_transient('kop_lawsuit_updates_lock')) {
        return;
    }
    set_transient('kop_lawsuit_updates_lock', 1, 5 * MINUTE_IN_SECONDS);
    $result = kop_apply_lawsuit_updates();
    if ($result !== null) {
        update_option('kop_lawsuit_updates_applied', $version, false);
        update_option('kop_lawsuit_updates_result', $result, false);
    }
    delete_transient('kop_lawsuit_updates_lock');
}
add_action('init', 'kop_maybe_apply_lawsuit_updates', 25);
