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
        foreach ($facilities as $entry) {
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
            $changes = kop_suggestion_diff(
                // Both sides through the normalizer, so a stored entry's key order never reads as a change.
                kop_suggestion_flatten($record ? kop_facility_normalize($record['doc'], array('facility_id' => $record['id'])) : array()),
                kop_suggestion_flatten($sent),
                $whole && $record
            );
            if (!$changes) {
                if ($record) $out['unchanged'][] = $name;
                continue;
            }
            $out['entries'][] = array(
                'name'        => $name,
                'facility_id' => $record ? $record['id'] : null,
                'url'         => $record ? kop_suggestion_facility_url($record['id']) : '',
                'is_new'      => !$record,
                'changes'     => $changes,
            );
        }

        // Referrers, transporters, providers' details, marketers, trade orgs: what was sent, empty parts left out.
        foreach ($data as $key => $value) {
            if (in_array($key, array('facilities', 'name', 'projectName', 'category', 'timestamp', 'currentFacilityIndex', 'documentFolderId'), true)) continue;
            foreach (kop_suggestion_flatten_any($value, kop_suggestion_label($key)) as $label => $text) {
                $out['other'][] = array('label' => $label, 'after' => $text);
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
     * as one "11-17".
     */
    function kop_suggestion_flatten(array $doc) {
        static $skip = array('schema_version', 'facility_id', 'provenance', 'legacy', 'documentFolderId',
            'identification.name', 'identification.nameKey', 'location.raw', 'sourceProject', 'sourceCategory', 'sourceOperator', 'addressParts', 'locationDetails');
        $out = array();
        $walk = function ($node, $path) use (&$walk, &$out, $skip) {
            if (in_array($path, $skip, true)) return;
            if ($path === 'facilityDetails.ageRange') {
                $min = $node['min'] ?? null;
                $max = $node['max'] ?? null;
                $out[$path] = ($min !== null && $min !== '') || ($max !== null && $max !== '') ? trim($min . '-' . $max, '-') : '';
                return;
            }
            if (is_array($node) && $node && array_keys($node) !== range(0, count($node) - 1)) {
                foreach ($node as $k => $v) $walk($v, $path === '' ? (string) $k : $path . '.' . $k);
                return;
            }
            if (is_array($node)) {
                $out[$path] = array_values(array_filter(array_map('kop_suggestion_item_text', $node), 'strlen'));
                return;
            }
            // A box left unticked is no information (the mini-form sends every flag false).
            $out[$path] = is_bool($node) ? ($node ? 'Yes' : '') : trim((string) $node);
        };
        $walk($doc, '');
        return $out;
    }
}

if (!function_exists('kop_suggestion_flatten_any')) {
    /** Any sent block as {label: text}, empty parts left out (the non-facility kinds). */
    function kop_suggestion_flatten_any($value, $label) {
        if (!is_array($value)) {
            $text = is_bool($value) ? ($value ? 'Yes' : 'No') : trim((string) $value);
            return $text === '' ? array() : array($label => $text);
        }
        if ($value && array_keys($value) === range(0, count($value) - 1)) {
            $items = array_values(array_filter(array_map('kop_suggestion_item_text', $value), 'strlen'));
            return $items ? array($label => implode("\n", $items)) : array();
        }
        $out = array();
        foreach ($value as $k => $v) {
            $out += kop_suggestion_flatten_any($v, $label . ': ' . kop_suggestion_label($k));
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
                    $rows[] = array('label' => $label, 'kind' => 'list', 'had' => count($old), 'added' => $added, 'dropped' => $dropped);
                }
                continue;
            }
            if ($empty($new)) {
                if ($whole && !$empty($old)) $rows[] = array('label' => $label, 'kind' => 'removed', 'before' => (string) $old, 'after' => '');
                continue;
            }
            if ($norm($new) === $norm($old)) continue;
            $rows[] = array('label' => $label, 'kind' => $empty($old) ? 'added' : 'changed', 'before' => $empty($old) ? '' : (string) $old, 'after' => (string) $new);
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
