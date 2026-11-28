<?php
/**
 * Records of a related industry (inc/related-facilities.php) and the articles about them, added on
 * deploy from seeds/related-records.json (kop_apply_related_record_seeds(), after the related moves).
 * First use: the elite boarding schools (owner decision 2026-11-28), whose news articles had been
 * deleted from the news queue because they are not troubled teen programs.
 *
 * Each entry, applied once (the log, option kop_related_record_seed_log, keeps it):
 *   {category, name, find?: [names a record already on file may have, such as one a related move
 *    brought over], other_names?, city?, state?, country?, status?, start_year?, note?,
 *    news?: [article ids], drop_links?: {article id: [record ids]}}
 * A record of that name (or a find name) in the category is reused; found under a find name it
 * takes the entry's name and keeps the old one as another name. Otherwise one is made, approved.
 * Each article is linked to the record and approved if it was not; an article in an entry whose
 * name is '' is filed under the category in general (related_category_news) instead.
 * drop_links takes an article's links to the listed TTI records off (a link an import guessed).
 * kop_related_record_seed_undo() puts each article's status, notes and links back, a renamed record's
 * row back, and deletes a record the seed made.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_related_record_seed_log')) {
    function kop_related_record_seed_log() {
        $v = get_option('kop_related_record_seed_log', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_related_record_seed_key')) {
    function kop_related_record_seed_key(array $e) {
        return (string) ($e['category'] ?? '') . '|' . (string) ($e['name'] ?? '');
    }
}

if (!function_exists('kop_related_record_seed_apply')) {
    /** One entry. Returns its log: {record, made, renamed_from, news: {id: old status}, general, dropped}. */
    function kop_related_record_seed_apply(PDO $pdo, array $e, $by = 'seed') {
        $category = (string) ($e['category'] ?? '');
        if (!kop_related_category($category)) throw new RuntimeException('Unknown category ' . $category . '.');
        $name = trim((string) ($e['name'] ?? ''));
        $opts = array('pdo' => $pdo, 'prefix' => isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb']->prefix : 'wp_', 'skip_memberships' => true);
        $log = array('record' => 0, 'made' => false, 'renamed_from' => '', 'news' => array(), 'linked' => array(), 'general' => array(),
            'dropped' => array(), 'notes' => array());

        if ($name !== '') {
            $state = ($e['state'] ?? '') !== '' ? (string) $e['state'] : null;
            $id = kop_related_find($category, $name, $state, $opts);
            $found_as = '';
            if (!$id) {
                foreach ((array) ($e['find'] ?? array()) as $n) {
                    if (($id = kop_related_find($category, (string) $n, null, $opts))) {
                        $found_as = (string) $n;
                        break;
                    }
                }
            }
            if ($id) {
                $rec = kop_related_get($id, $opts);
                if (!$rec) throw new RuntimeException('Record #' . $id . ' does not read.');
                $doc = $rec['doc'];
                if ($found_as !== '' && kop_facility_name_key($doc['identification']['name']) !== kop_facility_name_key($name)) {
                    $log['renamed_from'] = (string) $doc['identification']['name'];
                    $raw = $pdo->prepare('SELECT json_data, unique_name, updated_at FROM related_facilities WHERE id = ?');
                    $raw->execute(array((int) $id));
                    $log['before_row'] = $raw->fetch(PDO::FETCH_ASSOC);
                    $raw->closeCursor();
                    $doc['identification']['otherNames'] = array_values(array_unique(array_merge(
                        (array) ($doc['identification']['otherNames'] ?? array()), array($log['renamed_from']), (array) ($e['other_names'] ?? array()))));
                    $doc['identification']['name'] = $name;
                    foreach (array('city', 'state', 'country') as $k) {
                        if (($e[$k] ?? '') !== '' && trim((string) ($doc['location'][$k] ?? '')) === '') $doc['location'][$k] = (string) $e[$k];
                    }
                    if (!empty($e['note'])) $doc['notes'][] = (string) $e['note'];
                    kop_related_save($category, $doc, $id, $opts);
                }
            } else {
                $place = trim(implode(', ', array_filter(array((string) ($e['city'] ?? ''), (string) ($e['state'] ?? '') ?: (string) ($e['country'] ?? '')))));
                $id = kop_related_save($category, array(
                    'identification'  => array('name' => $name, 'otherNames' => (array) ($e['other_names'] ?? array())),
                    'locationDetails' => array('city' => (string) ($e['city'] ?? ''), 'state' => (string) ($e['state'] ?? ''),
                        'country' => (string) ($e['country'] ?? '') ?: (($e['state'] ?? '') !== '' ? 'United States' : '')),
                    'location'        => $place,
                    'operatingPeriod' => array('startYear' => $e['start_year'] ?? null, 'endYear' => null,
                        'status' => (string) ($e['status'] ?? 'Unknown'), 'notes' => array()),
                    'notes'           => !empty($e['note']) ? array((string) $e['note']) : array(),
                ), 0, $opts + array('review' => 'approved'));
                $log['made'] = true;
            }
            $log['record'] = (int) $id;
        }

        $status = $pdo->prepare('SELECT status, reviewer_notes FROM news_submissions WHERE id = ?');
        $has_link = $pdo->prepare('SELECT 1 FROM news_facility_links WHERE news_id = ? AND facility_id = ?');
        foreach ((array) ($e['news'] ?? array()) as $nid) {
            $nid = (int) $nid;
            $status->execute(array($nid));
            $row = $status->fetch(PDO::FETCH_ASSOC);
            $status->closeCursor();
            if (!$row) continue;   // not on this site
            $old = (string) $row['status'];
            if ($name !== '') {
                $has_link->execute(array($nid, (int) $log['record']));
                $linked = (bool) $has_link->fetchColumn();
                $has_link->closeCursor();
                if (!$linked) {
                    $pdo->prepare("INSERT INTO news_facility_links (news_id, facility_id, link_type, created_by) VALUES (?, ?, 'related', ?)")
                        ->execute(array($nid, (int) $log['record'], $by));
                    $log['linked'][] = $nid;
                }
            } elseif (kop_related_news_link($pdo, $category, $nid, $by)) {
                $log['general'][] = $nid;
            }
            foreach ((array) (($e['drop_links'] ?? array())[(string) $nid] ?? array()) as $fid) {
                $guessed = $pdo->prepare('SELECT * FROM news_facility_links WHERE news_id = ? AND facility_id = ?');
                $guessed->execute(array($nid, (int) $fid));
                foreach ($guessed->fetchAll(PDO::FETCH_ASSOC) as $link) $log['dropped'][] = $link;
                $pdo->prepare('DELETE FROM news_facility_links WHERE news_id = ? AND facility_id = ?')->execute(array($nid, (int) $fid));
            }
            $log['news'][$nid] = (string) $old;
            if (!in_array($old, array('approved', 'published'), true)) {
                $note = 'Filed under ' . kop_related_category($category)['label'] . ' (seeds/related-records.json, owner decision 2026-11-28); was ' . $old . '.';
                $pdo->prepare("UPDATE news_submissions SET status = 'approved', reviewer_notes = ? WHERE id = ?")
                    ->execute(array(trim(trim((string) $row['reviewer_notes']) . ' ' . $note), $nid));
                $log['notes'][$nid] = $row['reviewer_notes'];
            }
        }
        return $log;
    }
}

if (!function_exists('kop_apply_related_record_seeds')) {
    /** seeds/related-records.json on deploy; returns the keys applied. */
    function kop_apply_related_record_seeds() {
        $done = array();
        $path = trailingslashit(get_stylesheet_directory()) . 'seeds/related-records.json';
        if (!file_exists($path) || !function_exists('kop_related_ready') || !kop_related_ready()) return $done;
        $entries = json_decode((string) file_get_contents($path), true);
        $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
        if (!is_array($entries) || !$pdo) return $done;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $log = kop_related_record_seed_log();
        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            $key = kop_related_record_seed_key($e);
            if (isset($log[$key])) continue;
            try {
                $log[$key] = kop_related_record_seed_apply($pdo, $e);
                update_option('kop_related_record_seed_log', $log, false);
                $done[] = $key;
            } catch (Throwable $ex) {
                error_log('kop_apply_related_record_seeds ' . $key . ': ' . $ex->getMessage());
            }
        }
        if ($done && function_exists('kop_related_flush')) kop_related_flush();
        return $done;
    }
}

if (!function_exists('kop_related_record_seed_undo')) {
    /** Put one applied entry back: article statuses and links, and the record if the seed made it. */
    function kop_related_record_seed_undo(PDO $pdo, $key) {
        $log = kop_related_record_seed_log();
        if (!isset($log[$key])) return false;
        $l = $log[$key];
        [$category] = explode('|', $key, 2);
        foreach ($l['news'] as $nid => $old) {
            $pdo->prepare('UPDATE news_submissions SET status = ? WHERE id = ?')->execute(array((string) $old, (int) $nid));
            if (array_key_exists($nid, (array) ($l['notes'] ?? array()))) {
                $pdo->prepare('UPDATE news_submissions SET reviewer_notes = ? WHERE id = ?')->execute(array($l['notes'][$nid], (int) $nid));
            }
        }
        foreach ((array) ($l['linked'] ?? array()) as $nid) {
            $pdo->prepare('DELETE FROM news_facility_links WHERE news_id = ? AND facility_id = ?')->execute(array((int) $nid, (int) $l['record']));
        }
        foreach ($l['general'] as $nid) kop_related_news_unlink($pdo, $category, (int) $nid);
        foreach ($l['dropped'] as $row) {
            $cols = array_keys($row);
            $pdo->prepare('INSERT INTO news_facility_links (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($row));
        }
        if (!empty($l['before_row']) && !empty($l['record'])) {
            $b = $l['before_row'];
            $pdo->prepare('UPDATE related_facilities SET json_data = ?, unique_name = ?, updated_at = ? WHERE id = ?')
                ->execute(array($b['json_data'], $b['unique_name'], $b['updated_at'], (int) $l['record']));
            if (function_exists('kop_related_flush')) kop_related_flush();
        }
        if (!empty($l['made']) && !empty($l['record'])) kop_related_delete((int) $l['record'], array('pdo' => $pdo));
        unset($log[$key]);
        update_option('kop_related_record_seed_log', $log, false);
        return true;
    }
}
