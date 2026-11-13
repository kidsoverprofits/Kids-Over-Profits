<?php
/**
 * "What changed" for a data suggestion (suggested_edits): each facility the
 * suggestion names, matched to its record, with only the fields whose sent
 * value differs from the record. Submissions Review draws it on the card
 * (js/admin-submissions.js renderChanges()) so the reviewer never reads the
 * whole JSON to find the one line someone changed.
 *
 * Empty, "Unknown" and null sent values are no information and never show
 * (a mini-form leaves every field it did not touch empty). A list shows the
 * items it adds; items it drops show only for a whole-record edit (an entry
 * carrying its facility_id, sent from the full data form), where Approve
 * replaces the record.
 *
 *   kop_suggestion_changes($pdo, $payload, $reason, $prefix)
 *     -> {entries: [{name, facility_id, url, is_new, changes: [{label, before, after, kind}]}],
 *         unchanged: [names], other: [{label, after}] }
 */

if (!function_exists('kop_suggestion_changes')) {
    function kop_suggestion_changes(PDO $pdo, $payload, $reason = '', $prefix = '') {
        $out = array('entries' => array(), 'unchanged' => array(), 'other' => array());
        if (!is_array($payload)) return $out;
        $data = function_exists('kop_extract_project_data') ? kop_extract_project_data($payload) : $payload;
        if (!is_array($data)) return $out;

        $facilities = is_array($data['facilities'] ?? null) ? $data['facilities'] : array();
        $can_compare = function_exists('kop_facility_normalize') && function_exists('kop_facility_load');
        $opts = array('pdo' => $pdo, 'prefix' => $prefix);
        // Where "Move to" on the card can put a piece of text (kop_suggestion_edit()).
        $out['targets'] = array();
        foreach (kop_suggestion_move_targets() as $path => $kind) {
            $out['targets'][] = array('path' => $path, 'label' => kop_suggestion_label($path), 'list' => $kind === 'list');
        }
        foreach ($facilities as $index => $entry) {
            if (!is_array($entry)) continue;
            $name = trim((string) ($entry['identification']['name'] ?? $entry['name'] ?? ''));
            if ($name === '') continue;
            $whole = (int) ($entry['facility_id'] ?? 0) > 0;
            $record = null;
            $sent = $entry;
            if ($can_compare) {
                try {
                    $sent = kop_facility_normalize($entry);
                    $fid = kop_suggestion_find_facility($entry, $sent, (string) $reason, $opts);
                    $record = $fid ? kop_facility_load($fid, $opts) : null;
                } catch (Throwable $e) {
                    $record = null;
                }
            }
            // Both sides through the normalizer, so a stored entry's key order never reads as a change.
            $rec_doc = $record ? kop_facility_normalize($record['doc'], array('facility_id' => $record['id'])) : array();
            $rec_segs = array();
            $changes = kop_suggestion_diff(kop_suggestion_flatten($rec_doc, $rec_segs), kop_suggestion_flatten($sent), $whole && $record);
            if (!$changes) {
                if ($record) $out['unchanged'][] = $name;
                continue;
            }
            // What the card's Edit / Move / Remove need: each row's field, and each new list item's place and parts.
            $segs = array();
            kop_suggestion_flatten($sent, $segs);
            foreach ($changes as &$ch) {
                $raw = isset($segs[$ch['path']]) ? kop_suggestion_get($sent, $segs[$ch['path']]) : null;
                if ($ch['kind'] === 'list') {
                    $ch['items'] = kop_suggestion_row_items(is_array($raw) ? $raw : array(), $ch['added']);
                    // A whole-record edit that drops items: each can be kept ('keep' puts the record's own item back).
                    if ($ch['dropped']) {
                        $had = isset($rec_segs[$ch['path']]) ? kop_suggestion_get($rec_doc, $rec_segs[$ch['path']]) : null;
                        $ch['dropped_items'] = kop_suggestion_row_items(is_array($had) ? $had : array(), $ch['dropped']);
                    }
                } else {
                    // An emptied field (whole-record edit) is edited or kept; a tick box is only removed.
                    $ch['editable'] = !is_bool($raw);
                    $ch['keepable'] = $ch['kind'] === 'removed';
                }
            }
            unset($ch);
            $out['entries'][] = array(
                'index'       => $index,
                'name'        => $name,
                'facility_id' => $record ? $record['id'] : null,
                'url'         => $record ? kop_suggestion_facility_url($record['id']) : '',
                'is_new'      => !$record,
                'changes'     => $changes,
            );
        }

        // Referrers, transporters, providers' details, marketers, trade orgs: what was sent, empty parts left out.
        foreach ($data as $key => $value) {
            if (in_array($key, kop_suggestion_other_skip(), true)) continue;
            $paths = array();
            foreach (kop_suggestion_flatten_any($value, kop_suggestion_label($key), array($key), $paths) as $label => $text) {
                $row = array('label' => $label, 'after' => $text);
                // Text and lists of plain lines are edited whole (one item per line); a list of people or
                // other entries item by item (its parts); a tick box can only be removed.
                if (isset($paths[$label])) {
                    $cur = kop_suggestion_get($data, $paths[$label]);
                    $row['path'] = $paths[$label];
                    $row['bool'] = is_bool($cur);
                    $row['list'] = is_array($cur);
                    $row['value'] = $row['list'] ? $text : (is_bool($cur) ? '' : (string) $cur);
                    if (is_array($cur) && array_filter($cur, 'is_array')) {
                        $row['items'] = kop_suggestion_row_items($cur, array_map('kop_suggestion_item_text', $cur));
                    }
                    $row['moves'] = array();
                    foreach (kop_suggestion_other_targets($data, $paths[$label]) as $k => $l) $row['moves'][] = array('path' => $k, 'label' => $l);
                }
                $out['other'][] = $row;
            }
        }
        return $out;
    }
}

if (!function_exists('kop_suggestion_changes_safe')) {
    /** For the Submissions Review list: WordPress's table prefix, and null (the card shows the raw data) when it fails. */
    function kop_suggestion_changes_safe(PDO $pdo, $payload, $reason = '') {
        try {
            return kop_suggestion_changes($pdo, $payload, $reason, isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '');
        } catch (Throwable $e) {
            error_log('kop_suggestion_changes: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('kop_suggestion_find_facility')) {
    /**
     * The record an entry is about: its facility_id; else the facility page it
     * was sent from ("from /facility/<slug>/" in the reason); else name and
     * place (the state page it was sent from fills a missing state); else the
     * one facility with that name.
     */
    function kop_suggestion_find_facility(array $entry, array $sent, $reason, array $opts) {
        $fid = (int) ($entry['facility_id'] ?? 0);
        if ($fid > 0 && kop_facility_load($fid, $opts)) return $fid;
        // The app's and extension's corrections name the record they are about (inc/mobile-submit.php),
        // kept under legacy in the stored document: only a hint, the entry is still merged as a partial.
        $hint = (int) ($entry['facilityId'] ?? ($entry['legacy']['facilityId'] ?? 0));
        if ($hint > 0 && kop_facility_load($hint, $opts)) return $hint;

        if (preg_match('#from\s+/facility/([a-z0-9-]+)/?#i', $reason, $m) && function_exists('kop_facility_pages_index')) {
            try {
                $slugs = kop_facility_pages_index()['slugs'] ?? array();
                if (!empty($slugs[strtolower($m[1])])) return (int) $slugs[strtolower($m[1])];
            } catch (Throwable $e) {
                // No page index: name and place below.
            }
        }

        $state = $sent['location']['state'] ?? null;
        if (!$state && preg_match('#from\s+/([a-z-]+)/?(?:\s|\.|$)#i', $reason, $m) && function_exists('kop_facility_state_code')) {
            $state = kop_facility_state_code(str_replace('-', ' ', $m[1]));
        }
        $name = (string) ($sent['identification']['name'] ?? '');
        if ($state) {
            $fid = (int) kop_facility_resolve_identity($name, $state, $sent['location']['city'] ?? '', $opts);
            if ($fid > 0) return $fid;
        }

        $key = kop_facility_name_key($name);
        if ($key === '') return 0;
        $table = kop_facility_table('facilities', $opts);
        $rows = kop_facility_has_generated_columns($opts)
            ? kop_facility_db_rows("SELECT id FROM {$table} WHERE name_key = ?", array($key), $opts)
            : kop_facility_db_rows("SELECT id FROM {$table} WHERE JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.identification.nameKey')) = ?", array($key), $opts);
        return count($rows) === 1 ? (int) $rows[0]['id'] : 0;
    }
}

if (!function_exists('kop_suggestion_facility_url')) {
    function kop_suggestion_facility_url($fid) {
        if (!function_exists('kop_facility_pages_index') || !function_exists('kop_facility_pages_url_for_slug')) return '';
        $slug = kop_facility_pages_index()['ids'][(int) $fid]['slug'] ?? '';
        return $slug !== '' ? kop_facility_pages_url_for_slug($slug) : '';
    }
}

if (!function_exists('kop_suggestion_label')) {
    /** A field's name for people: the ones the form shows, else its key in words. */
    function kop_suggestion_label($path) {
        static $labels = array(
            'identification.name' => 'Name', 'identification.currentName' => 'Current name',
            'identification.otherNames' => 'Other names', 'identification.pastNames' => 'Past names',
            'identification.legalName' => 'Legal name', 'identification.dbaNames' => 'Also does business as',
            'identification.currentOperator' => 'Operator', 'identification.currentOwners' => 'Owners',
            'identification.otherOperators' => 'Other operators', 'identification.pastOperators' => 'Past operators',
            'identification.knownReferrers' => 'Known referrers', 'identification.investors' => 'Investors',
            'location.street' => 'Street', 'location.city' => 'City', 'location.state' => 'State', 'location.zip' => 'ZIP',
            'location.country' => 'Country', 'location.text' => 'Location', 'location.additionalLocations' => 'Other locations',
            'location.formerLocations' => 'Former locations',
            'operatingPeriod.startYear' => 'Opened', 'operatingPeriod.endYear' => 'Closed', 'operatingPeriod.status' => 'Status',
            'operatingPeriod.yearsOfOperation' => 'Years open', 'operatingPeriod.notes' => 'Status notes',
            'facilityDetails.type' => 'Program type', 'facilityDetails.capacity' => 'Capacity',
            'facilityDetails.currentCensus' => 'Census', 'facilityDetails.ageRange' => 'Ages', 'facilityDetails.gender' => 'Gender',
            'facilityDetails.isPrivatelyOwned' => 'Privately owned',
            'staff.administrator' => 'Administrators', 'staff.notableStaff' => 'Staff', 'staff.pastTTIJobs' => 'Staff past jobs',
            'accreditations.current' => 'Accreditations', 'accreditations.past' => 'Past accreditations',
            'profileLinks' => 'Profile links', 'resourceLinks' => 'Materials and links', 'notes' => 'Notes',
            'fieldNotes' => 'Field notes', 'survivorTestimony' => 'Survivor testimony', 'criticalIncidents' => 'Incidents',
            'ttiPractices' => 'TTI practices', 'federalFunding' => 'Federal funding',
            'criticalIncidents.customIncidents' => 'Incidents',
        );
        if (isset($labels[$path])) return $labels[$path];
        // fieldNotes keys are the field's own path ("ttiPractices.hasRestraint") or, for a note on a
        // list item, a random "field-<time>-<random>" (js/data-form/notes.js) that names no field.
        if (strpos((string) $path, 'fieldNotes.') === 0) {
            $rest = substr($path, strlen('fieldNotes.'));
            return preg_match('/^field-\d+-[a-z0-9]+$/i', $rest) ? 'Field notes' : 'Field notes: ' . strtolower(kop_suggestion_label($rest));
        }
        $parts = explode('.', (string) $path);
        $last = array_pop($parts);
        $words = strtolower($last) === 'custom' ? 'Other' : ucfirst(strtolower(trim(preg_replace('/(?<=[a-z])(?=[A-Z])|_/', ' ', $last))));
        // "criticalIncidents.custom" reads "Incidents: custom", not "Custom".
        return $parts && in_array(strtolower($last), array('custom', 'current', 'past', 'details', 'other', 'list', 'items'), true)
            ? kop_suggestion_label(implode('.', $parts)) . ': ' . strtolower($words)
            : $words;
    }
}

if (!function_exists('kop_suggestion_item_text')) {
    /** One list item as a line: a note's text, a link's title and address, else its filled values joined. */
    function kop_suggestion_item_text($item) {
        if (!is_array($item)) return trim((string) $item);
        // A form note ({id, text, timestamp}): its id and time are bookkeeping.
        if (isset($item['text']) && !array_diff(array_keys($item), array('id', 'text', 'timestamp'))) return trim((string) $item['text']);
        if (!empty($item['url'])) {
            $title = trim((string) ($item['title'] ?? $item['label'] ?? $item['name'] ?? ''));
            return $title !== '' && $title !== $item['url'] ? $title . ' (' . $item['url'] . ')' : (string) $item['url'];
        }
        $parts = array();
        array_walk_recursive($item, function ($v) use (&$parts) {
            if (is_bool($v) || $v === null) return;
            $v = trim((string) $v);
            if ($v !== '') $parts[] = $v;
        });
        return implode(', ', array_unique($parts));
    }
}

if (!function_exists('kop_suggestion_flatten')) {
    /**
     * A facility document as {path: text | [item texts]}: the bookkeeping parts
     * (ids, name key, provenance, the address as typed) left out, the age range
     * as one "11-17". $segments, when passed, gets each path's keys as a list
     * (a fieldNotes key can itself hold dots, so the path alone is not enough).
     */
    function kop_suggestion_flatten(array $doc, &$segments = null) {
        static $skip = array('schema_version', 'facility_id', 'provenance', 'legacy', 'documentFolderId',
            'identification.name', 'identification.nameKey', 'location.raw', 'sourceProject', 'sourceCategory', 'sourceOperator', 'addressParts', 'locationDetails');
        $out = array();
        $segments = array();
        $walk = function ($node, $path, array $segs) use (&$walk, &$out, &$segments, $skip) {
            if (in_array($path, $skip, true)) return;
            if ($path === 'facilityDetails.ageRange') {
                $min = $node['min'] ?? null;
                $max = $node['max'] ?? null;
                $out[$path] = ($min !== null && $min !== '') || ($max !== null && $max !== '') ? trim($min . '-' . $max, '-') : '';
                $segments[$path] = $segs;
                return;
            }
            if (is_array($node) && $node && array_keys($node) !== range(0, count($node) - 1)) {
                foreach ($node as $k => $v) $walk($v, $path === '' ? (string) $k : $path . '.' . $k, array_merge($segs, array((string) $k)));
                return;
            }
            $segments[$path] = $segs;
            if (is_array($node)) {
                $out[$path] = array_values(array_filter(array_map('kop_suggestion_item_text', $node), 'strlen'));
                return;
            }
            // A box left unticked is no information (the mini-form sends every flag false).
            $out[$path] = is_bool($node) ? ($node ? 'Yes' : '') : trim((string) $node);
        };
        $walk($doc, '', array());
        return $out;
    }
}

if (!function_exists('kop_suggestion_flatten_any')) {
    /**
     * Any sent block as {label: text}, empty parts left out (the non-facility kinds).
     * $paths gets {label: [keys]} for the parts the card can edit: text, and lists of plain lines.
     */
    function kop_suggestion_flatten_any($value, $label, array $segs = array(), &$paths = null) {
        if (!is_array($paths)) $paths = array();
        if (!is_array($value)) {
            $text = is_bool($value) ? ($value ? 'Yes' : 'No') : trim((string) $value);
            if ($text !== '' && $segs) $paths[$label] = $segs;
            return $text === '' ? array() : array($label => $text);
        }
        if ($value && array_keys($value) === range(0, count($value) - 1)) {
            $items = array_values(array_filter(array_map('kop_suggestion_item_text', $value), 'strlen'));
            if ($items && $segs) $paths[$label] = $segs;
            return $items ? array($label => implode("\n", $items)) : array();
        }
        $out = array();
        foreach ($value as $k => $v) {
            $out += kop_suggestion_flatten_any($v, $label . ': ' . kop_suggestion_label($k), array_merge($segs, array((string) $k)), $paths);
        }
        return $out;
    }
}

if (!function_exists('kop_suggestion_other_skip')) {
    /** Top-level keys of a sent project that are bookkeeping, not something sent in. */
    function kop_suggestion_other_skip() {
        return array('facilities', 'name', 'projectName', 'category', 'timestamp', 'currentFacilityIndex', 'documentFolderId');
    }
}

if (!function_exists('kop_suggestion_get')) {
    /** The value at a list of keys, or null. */
    function kop_suggestion_get($node, array $segs) {
        foreach ($segs as $k) {
            if (!is_array($node) || !array_key_exists($k, $node)) return null;
            $node = $node[$k];
        }
        return $node;
    }
}

if (!function_exists('kop_suggestion_put')) {
    /** Set the value at a list of keys, making the maps on the way. */
    function kop_suggestion_put(array $node, array $segs, $value) {
        $k = array_shift($segs);
        if (!$segs) {
            $node[$k] = $value;
            return $node;
        }
        $node[$k] = kop_suggestion_put(is_array($node[$k] ?? null) ? $node[$k] : array(), $segs, $value);
        return $node;
    }
}

if (!function_exists('kop_suggestion_item_parts')) {
    /**
     * The parts of one list item the card lets a reviewer edit: a plain line is
     * one part (key ''); a person is name, role and past jobs; a note its text.
     * Ids, times, kinds and the publish flag are never edited here.
     */
    function kop_suggestion_item_parts($item) {
        if (!is_array($item)) return array(array('key' => '', 'label' => '', 'value' => trim((string) $item)));
        static $skip = array('id', 'timestamp', 'nameKey', 'employer', 'kind', 'movedFrom', 'personId', 'publish', 'country');
        static $labels = array('name' => 'Name', 'role' => 'Role', 'pastJobs' => 'Past jobs', 'text' => 'Text', 'source' => 'Source',
            'sourceUrl' => 'Source link', 'url' => 'Link', 'label' => 'Title', 'title' => 'Title', 'note' => 'Note', 'date' => 'Date',
            'organization' => 'Organization', 'raw' => 'Address', 'street' => 'Street', 'city' => 'City', 'state' => 'State', 'zip' => 'ZIP');
        $parts = array();
        foreach ($item as $k => $v) {
            if (in_array($k, $skip, true) || !(is_string($v) || $v === null)) continue;
            $parts[] = array('key' => (string) $k, 'label' => $labels[$k] ?? ucfirst(strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string) $k))), 'value' => (string) $v);
        }
        return $parts;
    }
}

if (!function_exists('kop_suggestion_row_items')) {
    /** For a list row on the card: each new item's place in the sent list, its text and its parts. */
    function kop_suggestion_row_items(array $list, array $added) {
        $want = array_map(function ($t) { return mb_strtolower(trim((string) $t)); }, $added);
        $items = array();
        foreach (array_values($list) as $i => $item) {
            $text = kop_suggestion_item_text($item);
            if ($text === '' || !in_array(mb_strtolower($text), $want, true)) continue;
            $items[] = array('index' => $i, 'text' => $text, 'parts' => kop_suggestion_item_parts($item));
        }
        return $items;
    }
}

if (!function_exists('kop_suggestion_move_targets')) {
    /** The fields "Move to" offers on the card: path => 'list' | 'text'. */
    function kop_suggestion_move_targets() {
        return array(
            'notes' => 'list', 'criticalIncidents.customIncidents' => 'list', 'survivorTestimony' => 'list',
            'operatingPeriod.notes' => 'list', 'staff.notableStaff' => 'list', 'staff.administrator' => 'list',
            'identification.currentName' => 'text', 'identification.otherNames' => 'list', 'identification.pastNames' => 'list',
            'identification.legalName' => 'text', 'identification.currentOperator' => 'text', 'identification.otherOperators' => 'list',
            'identification.pastOperators' => 'list', 'identification.currentOwners' => 'list', 'identification.pastOwners' => 'list',
            'identification.knownReferrers' => 'list', 'identification.investors' => 'list',
            'location.street' => 'text', 'location.city' => 'text', 'location.text' => 'text',
            'operatingPeriod.yearsOfOperation' => 'text', 'facilityDetails.type' => 'text',
        );
    }
}

if (!function_exists('kop_suggestion_edit')) {
    /**
     * A reviewer's fix to a waiting data suggestion before Approve, on the card
     * (api/manage-submissions.php 'edit_suggestion'). Returns the payload with
     * the fix made; throws RuntimeException with a sentence for the reviewer.
     *
     * $req: entry (a facility's index in facilities, or 'other' for the
     * referrer / transporter / provider blocks), op, path (a card row's path;
     * for 'other' its list of keys), index (a list item's place), and
     *   set    value: the field's new text ("11-17" for ages; for an 'other' list, one item per line)
     *   item   parts: {key: text} for that list item (key '' for a plain line); all empty removes it
     *   remove the field emptied, or the list item taken out
     *   move   to: a path from kop_suggestion_move_targets(); the text leaves its field and is
     *          added to that list, or fills that field (refused when it already holds something)
     * The facility entry is stored normalized (kop_suggestion_normalize_facilities()), as
     * api/save-suggestion.php stores new ones; Approve normalizes either shape the same way.
     */
    function kop_suggestion_edit($payload, array $req, $record_doc = null) {
        if (!is_array($payload)) throw new RuntimeException('This suggestion has no data to edit.');
        $op = (string) ($req['op'] ?? '');
        if (!in_array($op, array('set', 'item', 'remove', 'move', 'keep'), true)) throw new RuntimeException('Unknown edit.');
        $entry = $req['entry'] ?? null;
        if ($op === 'keep' && $entry === 'other') throw new RuntimeException('Unknown edit.');
        $fn = $entry === 'other'
            ? function (array $data) use ($req, $op) { return kop_suggestion_edit_other($data, $req, $op); }
            : function (array $data) use ($req, $op, $entry, $record_doc) {
                $i = (int) $entry;
                if (!is_numeric($entry) || !is_array($data['facilities'][$i] ?? null)) throw new RuntimeException('That facility is not in this suggestion any more. Reload the page.');
                $data['facilities'][$i] = kop_suggestion_edit_facility($data['facilities'][$i], $req, $op, $record_doc);
                return $data;
            };
        // The same unwrapping as kop_extract_project_data(), written back at the same depth.
        $apply = function ($node, $depth) use (&$apply, $fn) {
            if (is_array($node) && isset($node['data']) && is_array($node['data']) && $depth < 3) {
                $node['data'] = $apply($node['data'], $depth + 1);
                return $node;
            }
            return $fn(is_array($node) ? $node : array());
        };
        return $apply($payload, 0);
    }
}

if (!function_exists('kop_suggestion_edit_facility')) {
    function kop_suggestion_edit_facility(array $entry, array $req, $op, $record_doc = null) {
        if (!function_exists('kop_facility_normalize')) require_once dirname(__DIR__) . '/inc/facility-store.php';
        if (!function_exists('kop_suggestion_normalize_facilities')) require_once __DIR__ . '/lib-suggested-edits.php';
        $norm = function (array $doc) {
            return kop_suggestion_normalize_facilities(array('facilities' => array($doc)), 'locations')['facilities'][0];
        };
        $doc = $norm($entry);
        $path = (string) ($req['path'] ?? '');
        $segs = array();
        kop_suggestion_flatten($doc, $segs);
        if ($path === 'identification.name') {
            $segs[$path] = array('identification', 'name');   // a new facility's name, from the card's heading
        }
        if (!isset($segs[$path])) throw new RuntimeException('That field is not in this suggestion any more. Reload the page.');
        $at = $segs[$path];
        $cur = kop_suggestion_get($doc, $at);
        $label = kop_suggestion_label($path);
        $has_index = isset($req['index']) && $req['index'] !== '' && $req['index'] !== null;
        $index = (int) ($req['index'] ?? -1);

        if ($op === 'keep') {
            // A whole-record edit dropped this from the record: put the record's own value or item back.
            if (!is_array($record_doc)) throw new RuntimeException('The record this suggestion changes was not found.');
            $rec_segs = array();
            kop_suggestion_flatten($record_doc, $rec_segs);
            $had = isset($rec_segs[$path]) ? kop_suggestion_get($record_doc, $rec_segs[$path]) : null;
            if (is_array($cur) && $path !== 'facilityDetails.ageRange') {
                $had = is_array($had) ? array_values($had) : array();
                if (!$has_index || !array_key_exists($index, $had)) throw new RuntimeException('That item is not on the record any more. Reload the page.');
                $list = array_values($cur);
                $text = mb_strtolower(kop_suggestion_item_text($had[$index]));
                foreach ($list as $item) {
                    if (mb_strtolower(kop_suggestion_item_text($item)) === $text) return $norm($doc);   // already back
                }
                $list[] = $had[$index];
                return $norm(kop_suggestion_put($doc, $at, $list));
            }
            if ($had === null) throw new RuntimeException('The record has nothing under ' . $label . ' to keep.');
            return $norm(kop_suggestion_put($doc, $at, $had));
        }
        if (is_array($cur) && $path !== 'facilityDetails.ageRange' && ($op !== 'set') && (!$has_index || !array_key_exists($index, array_values($cur)))) {
            throw new RuntimeException('That item is not in the list any more. Reload the page.');
        }
        $list = is_array($cur) && $path !== 'facilityDetails.ageRange' ? array_values($cur) : null;
        if ($op === 'item' && $list === null) throw new RuntimeException($label . ' is not a list.');

        if ($op === 'set') {
            if ($list !== null) throw new RuntimeException('Edit the items of ' . $label . ' one at a time.');
            if (is_bool($cur)) throw new RuntimeException($label . ' is a tick box: remove it, or leave it.');
            $value = trim((string) ($req['value'] ?? ''));
            if ($path === 'identification.name' && $value === '') throw new RuntimeException('The name cannot be empty.');
            if (in_array($path, array('operatingPeriod.startYear', 'operatingPeriod.endYear', 'facilityDetails.capacity', 'facilityDetails.currentCensus'), true)
                && $value !== '' && !ctype_digit($value)) {
                throw new RuntimeException($label . ' takes a number only. Move any words to Notes.');
            }
            if ($path === 'facilityDetails.ageRange') {
                if ($value !== '' && !preg_match('/^\s*(\d{1,2})?\s*(?:-|–|to)?\s*(\d{1,2})?\s*$/', $value, $m)) {
                    throw new RuntimeException('Write the ages as 12-17.');
                }
                $doc = kop_suggestion_put($doc, $at, array('min' => isset($m[1]) && $m[1] !== '' ? (int) $m[1] : null, 'max' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null));
            } else {
                $doc = kop_suggestion_put($doc, $at, $value);
            }
            return $norm($doc);
        }

        if ($op === 'item') {
            $parts = is_array($req['parts'] ?? null) ? $req['parts'] : array();
            $item = $list[$index];
            if (!is_array($item)) {
                $item = trim((string) ($parts[''] ?? $item));
                $empty = $item === '';
            } else {
                $allowed = array_column(kop_suggestion_item_parts($item), 'key');
                foreach ($parts as $k => $v) {
                    if (in_array((string) $k, $allowed, true)) $item[$k] = trim((string) $v);
                }
                $empty = kop_suggestion_item_text(array_intersect_key($item, array_flip($allowed))) === '';
            }
            if ($empty) array_splice($list, $index, 1); else $list[$index] = $item;
            return $norm(kop_suggestion_put($doc, $at, $list));
        }

        // remove and move both take the piece out of where it is.
        if ($list !== null) {
            $text = kop_suggestion_item_text($list[$index]);
            array_splice($list, $index, 1);
            $doc = kop_suggestion_put($doc, $at, $list);
        } else {
            if ($path === 'identification.name') throw new RuntimeException('The name cannot be moved or removed.');
            $text = is_array($cur) ? trim(($cur['min'] ?? '') . '-' . ($cur['max'] ?? ''), '-')   // ages, as the card shows them
                : (is_bool($cur) ? '' : trim((string) $cur));
            $doc = kop_suggestion_put($doc, $at, is_bool($cur) ? false : ($path === 'facilityDetails.ageRange' ? array('min' => null, 'max' => null) : ''));
        }
        if ($op === 'remove') return $norm($doc);

        $to = (string) ($req['to'] ?? '');
        $targets = kop_suggestion_move_targets();
        if (!isset($targets[$to])) throw new RuntimeException('Pick where it goes.');
        if ($to === $path) throw new RuntimeException('It is already under ' . $label . '.');
        if ($text === '') throw new RuntimeException('There is no text to move.');
        $dest = explode('.', $to);
        $have = kop_suggestion_get($doc, $dest);
        if ($targets[$to] === 'list') {
            $items = is_array($have) ? array_values($have) : array();
            $items[] = $text;   // the normalizer gives it the list's shape (a person's name, a testimony's text)
            $doc = kop_suggestion_put($doc, $dest, $items);
        } else {
            if (trim((string) $have) !== '') {
                throw new RuntimeException(kop_suggestion_label($to) . ' already says "' . trim((string) $have) . '". Edit or move that first.');
            }
            $doc = kop_suggestion_put($doc, $dest, $text);
        }
        return $norm($doc);
    }
}

if (!function_exists('kop_suggestion_edit_other')) {
    /** set / remove / move for the referrer, transporter and provider blocks: text, or a list of plain lines. */
    function kop_suggestion_edit_other(array $data, array $req, $op) {
        $at = is_array($req['path'] ?? null) ? array_values(array_map('strval', $req['path'])) : array();
        if (!$at || in_array($at[0], kop_suggestion_other_skip(), true)) throw new RuntimeException('That field cannot be edited here.');
        $cur = kop_suggestion_get($data, $at);
        $seq = is_array($cur) && (!$cur || array_keys($cur) === range(0, count($cur) - 1));
        if ($cur === null || (is_array($cur) && !$seq)) throw new RuntimeException('That field cannot be edited here. Reload the page.');
        // A tick box ("Independent consultant: Yes"): only taken off.
        if (is_bool($cur)) {
            if ($op !== 'remove') throw new RuntimeException('A tick box can only be removed.');
            return kop_suggestion_put($data, $at, false);
        }
        $lines = function ($v) { return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $v)), 'strlen')); };
        $parent = array_slice($at, 0, -1);
        $siblings = kop_suggestion_get($data, $parent);
        // Where a moved piece lands: added to a list of lines, or into an empty text field.
        $land = function (array $data, $text) use ($req, $at, $parent, $siblings, $lines) {
            $to = (string) ($req['to'] ?? '');
            if ($to === '' || $to === end($at) || !is_array($siblings) || !array_key_exists($to, $siblings)
                || (count($at) === 1 && in_array($to, kop_suggestion_other_skip(), true))) throw new RuntimeException('Pick where it goes.');
            if (trim($text) === '') throw new RuntimeException('There is no text to move.');
            $dest = array_merge($parent, array($to));
            $have = $siblings[$to];
            if (is_array($have) && !array_filter($have, 'is_array')) {
                return kop_suggestion_put($data, $dest, array_merge(array_values($have), $lines($text)));
            }
            if (is_string($have) || $have === null) {
                if (trim((string) $have) !== '') throw new RuntimeException(kop_suggestion_label($to) . ' already has something in it. Edit or move that first.');
                return kop_suggestion_put($data, $dest, $text);
            }
            throw new RuntimeException('That field cannot take text.');
        };

        // A list of people or other entries (Transporters: name, role, company): item by item.
        if ($seq && array_filter($cur, 'is_array')) {
            $index = (int) ($req['index'] ?? -1);
            if (!isset($req['index']) || !array_key_exists($index, $cur)) throw new RuntimeException('That item is not in the list any more. Reload the page.');
            $list = $cur;
            $item = $list[$index];
            if ($op === 'item') {
                $parts = is_array($req['parts'] ?? null) ? $req['parts'] : array();
                if (!is_array($item)) {
                    $item = trim((string) ($parts[''] ?? $item));
                    $empty = $item === '';
                } else {
                    $allowed = array_column(kop_suggestion_item_parts($item), 'key');
                    foreach ($parts as $k => $v) {
                        if (in_array((string) $k, $allowed, true)) $item[$k] = trim((string) $v);
                    }
                    $empty = kop_suggestion_item_text(array_intersect_key($item, array_flip($allowed))) === '';
                }
                if ($empty) array_splice($list, $index, 1); else $list[$index] = $item;
                return kop_suggestion_put($data, $at, $list);
            }
            if ($op !== 'remove' && $op !== 'move') throw new RuntimeException('Edit the items of this list one at a time.');
            array_splice($list, $index, 1);
            $data = kop_suggestion_put($data, $at, $list);
            return $op === 'remove' ? $data : $land($data, kop_suggestion_item_text($item));
        }

        // Text, or a list of plain lines edited as one line each.
        $empty = $seq ? array() : '';
        if ($op === 'set') return kop_suggestion_put($data, $at, $seq ? $lines($req['value'] ?? '') : trim((string) ($req['value'] ?? '')));
        if ($op === 'remove') return kop_suggestion_put($data, $at, $empty);
        if ($op !== 'move') throw new RuntimeException('Unknown edit.');
        $text = $seq ? implode("\n", array_map('strval', $cur)) : trim((string) $cur);
        return $land(kop_suggestion_put($data, $at, $empty), $text);
    }
}

if (!function_exists('kop_suggestion_record_doc')) {
    /** The record a suggestion's facility entry is about, normalized as the card compares it ("Keep it"), or null. */
    function kop_suggestion_record_doc(PDO $pdo, $payload, $index, $reason, $prefix) {
        if (!function_exists('kop_facility_load') || !function_exists('kop_extract_project_data')) return null;
        $entry = kop_extract_project_data($payload)['facilities'][(int) $index] ?? null;
        if (!is_array($entry)) return null;
        $opts = array('pdo' => $pdo, 'prefix' => $prefix);
        try {
            $fid = kop_suggestion_find_facility($entry, kop_facility_normalize($entry), (string) $reason, $opts);
            $record = $fid ? kop_facility_load($fid, $opts) : null;
        } catch (Throwable $e) {
            error_log('kop_suggestion_record_doc: ' . $e->getMessage());
            return null;
        }
        return $record ? kop_facility_normalize($record['doc'], array('facility_id' => $record['id'])) : null;
    }
}

if (!function_exists('kop_suggestion_other_targets')) {
    /** For an 'other' row: the fields of the same block it can move to, {key: label}. */
    function kop_suggestion_other_targets(array $data, array $at) {
        $siblings = kop_suggestion_get($data, array_slice($at, 0, -1));
        $out = array();
        if (!is_array($siblings)) return $out;
        foreach ($siblings as $k => $v) {
            if ((string) $k === (string) end($at)) continue;
            if (count($at) === 1 && in_array((string) $k, kop_suggestion_other_skip(), true)) continue;   // the project's own keys
            if (is_string($v) || $v === null || (is_array($v) && !array_filter($v, 'is_array') && (!$v || array_keys($v) === range(0, count($v) - 1)))) {
                $out[(string) $k] = kop_suggestion_label((string) $k);
            }
        }
        return $out;
    }
}

if (!function_exists('kop_suggestion_diff')) {
    /**
     * Rows for the fields the sent document changes. $whole: Approve replaces
     * the record, so emptied fields and dropped list items show too.
     */
    function kop_suggestion_diff(array $before, array $after, $whole) {
        $norm = function ($s) { return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s))); };
        $empty = function ($v) use ($norm) {
            return is_array($v) ? !$v : in_array($norm($v), array('', 'unknown', 'null'), true);
        };
        $rows = array();
        foreach ($after as $path => $new) {
            $old = $before[$path] ?? (is_array($new) ? array() : '');
            $label = kop_suggestion_label($path);
            if (is_array($new) || is_array($old)) {
                $new = (array) $new;
                $old = (array) $old;
                $old_keys = array_map($norm, $old);
                $new_keys = array_map($norm, $new);
                $added = array_values(array_filter($new, function ($v) use ($norm, $old_keys) { return !in_array($norm($v), $old_keys, true); }));
                $dropped = $whole ? array_values(array_filter($old, function ($v) use ($norm, $new_keys) { return !in_array($norm($v), $new_keys, true); })) : array();
                if ($added || $dropped) {
                    $rows[] = array('label' => $label, 'path' => $path, 'kind' => 'list', 'had' => count($old), 'added' => $added, 'dropped' => $dropped);
                }
                continue;
            }
            if ($empty($new)) {
                if ($whole && !$empty($old)) $rows[] = array('label' => $label, 'path' => $path, 'kind' => 'removed', 'before' => (string) $old, 'after' => '');
                continue;
            }
            if ($norm($new) === $norm($old)) continue;
            $rows[] = array('label' => $label, 'path' => $path, 'kind' => $empty($old) ? 'added' : 'changed', 'before' => $empty($old) ? '' : (string) $old, 'after' => (string) $new);
        }
        return $rows;
    }
}

if (!function_exists('kop_suggestion_filled')) {
    /** A sent value that says something: not empty, "Unknown", null or an unticked box (the card's rule). */
    function kop_suggestion_filled($v) {
        if (is_array($v)) return (bool) $v;
        if ($v === null || $v === false) return false;
        return !in_array(mb_strtolower(trim((string) $v)), array('', 'unknown', 'null'), true);
    }
}

if (!function_exists('kop_suggestion_merge_into')) {
    /**
     * A mini-form entry laid onto the record it is about: each filled value
     * replaces the record's, each list gains the items it lacks, and nothing
     * the entry leaves empty is touched. Ids, the name and provenance stay the
     * record's. Returns the record's document with facility_id set.
     */
    function kop_suggestion_merge_into(array $record_doc, array $entry, $facility_id) {
        $sent = kop_facility_normalize($entry);
        $keep = array('schema_version', 'facility_id', 'provenance', 'legacy', 'documentFolderId');
        $merge = function (array $base, array $over, $path) use (&$merge, $keep) {
            foreach ($over as $k => $v) {
                $p = $path === '' ? (string) $k : $path . '.' . $k;
                if (in_array($p, $keep, true) || in_array($p, array('identification.name', 'identification.nameKey'), true)) continue;
                if (is_array($v) && $v && array_keys($v) !== range(0, count($v) - 1)) {
                    $base[$k] = $merge(is_array($base[$k] ?? null) ? $base[$k] : array(), $v, $p);
                } elseif (is_array($v)) {
                    $have = is_array($base[$k] ?? null) ? array_values($base[$k]) : array();
                    $seen = array_map(function ($i) { return mb_strtolower(kop_suggestion_item_text($i)); }, $have);
                    foreach ($v as $item) {
                        $text = mb_strtolower(kop_suggestion_item_text($item));
                        if ($text === '' || in_array($text, $seen, true)) continue;
                        $have[] = $item;
                        $seen[] = $text;
                    }
                    $base[$k] = $have;
                } elseif (kop_suggestion_filled($v)) {
                    $base[$k] = $v;
                }
            }
            return $base;
        };
        $doc = $merge($record_doc, $sent, '');
        $doc['facility_id'] = (int) $facility_id;
        return $doc;
    }
}

if (!function_exists('kop_suggestion_resolve_entries')) {
    /**
     * Before Approve saves a suggestion: each facility entry without a
     * facility_id that is about a record on file (kop_suggestion_find_facility())
     * becomes that record with the entry's filled values merged in, so the save
     * updates it instead of making a second, mostly blank facility.
     * Returns {facilities, all_on_file}: all_on_file when every entry is now a
     * record on file, which Approve then saves alone (no company is made from
     * a mini-form's facility name).
     */
    function kop_suggestion_resolve_entries(PDO $pdo, array $facilities, $reason, $prefix) {
        $opts = array('pdo' => $pdo, 'prefix' => $prefix);
        $all = (bool) $facilities;
        $merged_any = false;
        foreach ($facilities as $i => $entry) {
            if (!is_array($entry)) { $all = false; continue; }
            if ((int) ($entry['facility_id'] ?? 0) > 0) continue;
            $fid = kop_suggestion_find_facility($entry, kop_facility_normalize($entry), (string) $reason, $opts);
            $record = $fid ? kop_facility_load($fid, $opts) : null;
            if (!$record) { $all = false; continue; }
            $facilities[$i] = kop_suggestion_merge_into($record['doc'], $entry, $record['id']);
            $merged_any = true;
        }
        return array('facilities' => $facilities, 'all_on_file' => $all && $merged_any);
    }
}
