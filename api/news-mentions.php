<?php
/**
 * Shared helper for normalizing news_submissions.facilities_mentioned values.
 *
 * As of the news-linking migration, facilities_mentioned is stored as an array
 * of objects: [{name, facility_id}, ...]
 *
 * Older rows may still contain plain strings. This helper accepts either shape
 * and always returns a uniform list of objects so callers don't have to branch.
 */

if (!function_exists('kop_normalize_facility_mentions')) {
    /**
     * Normalize a facilities_mentioned value (string or already-decoded array)
     * into a list of ['name' => string, 'facility_id' => int|null] entries.
     *
     * @param mixed $value JSON string OR decoded array OR null.
     * @return array<int, array{name:string, facility_id:?int}>
     */
    function kop_normalize_facility_mentions($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) return [];

        $out = [];
        $seen = []; // dedupe by lowercase name
        foreach ($value as $item) {
            $name = '';
            $fid = null;

            if (is_string($item)) {
                $name = trim($item);
            } elseif (is_array($item)) {
                $name = trim((string)($item['name'] ?? ''));
                if (isset($item['facility_id']) && $item['facility_id'] !== '' && $item['facility_id'] !== null) {
                    $fid = (int)$item['facility_id'];
                    if ($fid <= 0) $fid = null;
                }
            }

            if ($name === '') continue;
            $key = strtolower($name);
            if (isset($seen[$key])) {
                // Prefer an entry that has a facility_id over one that doesn't.
                if ($fid !== null && $out[$seen[$key]]['facility_id'] === null) {
                    $out[$seen[$key]]['facility_id'] = $fid;
                }
                continue;
            }
            $seen[$key] = count($out);
            $out[] = ['name' => $name, 'facility_id' => $fid];
        }
        return $out;
    }
}

if (!function_exists('kop_facility_mention_names')) {
    /**
     * Pull just the name strings out of a facilities_mentioned value, for legacy
     * consumers that only care about the display names.
     *
     * @param mixed $value
     * @return string[]
     */
    function kop_facility_mention_names($value): array {
        $items = kop_normalize_facility_mentions($value);
        return array_map(static function ($m) { return $m['name']; }, $items);
    }
}

require_once __DIR__ . '/facility-aliases.php';

if (!function_exists('kop_sync_news_facility_links')) {
    /**
     * Sync news_facility_links rows for a given news submission based on the
     * normalized facilities_mentioned list.
     *
     *   - Deletes any existing links whose facility_id is no longer in the list.
     *   - Inserts new links for facility_ids that aren't already linked.
     *
     * Mentions that arrive without a facility_id (free-text or AI-extracted names)
     * are resolved by name against facilities_master - matching unique_name, the
     * operating company's name, and the curated "Other Names / Former Names" aliases
     * via the shared index in api/facility-aliases.php. This is the same resolution
     * the retroactive backfill uses, so new articles link operators and renamed
     * facilities the same way. A name with no confident match stays text-only.
     */
    function kop_sync_news_facility_links(PDO $pdo, int $newsId, array $normalizedMentions, ?string $createdBy = null): void {
        $desiredIds = [];
        $unresolvedNames = [];
        foreach ($normalizedMentions as $m) {
            if (!empty($m['facility_id'])) {
                $desiredIds[(int)$m['facility_id']] = true;
            } elseif (!empty($m['name'])) {
                $unresolvedNames[$m['name']] = true;
            }
        }

        // Built once per request and cached; the index reads every facility and
        // operator record, so we avoid rebuilding it if this runs again in the same save.
        static $aliasIndex = null;
        if ($aliasIndex === null && ($desiredIds || $unresolvedNames)) {
            $aliasIndex = kop_build_facility_alias_index($pdo);
        }
        foreach (array_keys($unresolvedNames) as $name) {
            $fid = kop_resolve_mention_to_facility((string)$name, $aliasIndex);
            if ($fid !== null) {
                $desiredIds[$fid] = true;
            }
        }

        // A company's name resolves to its operator id: that goes on the
        // operator's page, never into news_facility_links.
        $operatorIds = $aliasIndex ? array_keys(array_intersect_key($desiredIds, $aliasIndex['operators'] ?? [])) : [];
        foreach ($operatorIds as $oid) unset($desiredIds[$oid]);
        if ($operatorIds) kop_link_mentioned_operators($pdo, $aliasIndex, 'news', $newsId, $operatorIds, $createdBy);

        $desiredIds = array_keys($desiredIds);

        // Only prune links this sync owns (link_type 'mentioned'). Links created
        // manually in the admin panel (primary/related) must survive a re-save
        // whose facilities_mentioned list doesn't include them.
        if (empty($desiredIds)) {
            $stmt = $pdo->prepare("DELETE FROM news_facility_links WHERE news_id = ? AND link_type = 'mentioned'");
            $stmt->execute([$newsId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($desiredIds), '?'));
            $stmt = $pdo->prepare(
                "DELETE FROM news_facility_links WHERE news_id = ? AND link_type = 'mentioned' AND facility_id NOT IN ($placeholders)"
            );
            $stmt->execute(array_merge([$newsId], $desiredIds));

            // The offline tests run on SQLite, which spells it INSERT OR IGNORE.
            $verb = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            $ins = $pdo->prepare(
                "$verb INTO news_facility_links (news_id, facility_id, link_type, created_by)
                 VALUES (?, ?, 'mentioned', ?)"
            );
            foreach ($desiredIds as $fid) {
                $ins->execute([$newsId, $fid, $createdBy]);
            }
        }
    }
}

if (!function_exists('kop_news_mention_links')) {
    /**
     * The names to show under each news card, linked to the record pages the
     * article is linked to: news id => [['name' => ..., 'url' => ...|''], ...].
     *
     * Reads the stored links (news_facility_links, kop_operator_links), never
     * resolves a name afresh, so the feed links exactly what the facility and
     * operator pages list. A mention links when it is a linked record's name
     * (or its own facility_id, or the record Facilities from News matched it
     * to); a linked record no mention names is added under its own name, so
     * every link shows. Names with no link stay text.
     *
     * @param array $rows news_submissions rows (id, facilities_mentioned)
     */
    function kop_news_mention_links(PDO $pdo, array $rows): array {
        $ids = array_values(array_unique(array_filter(array_map(static function ($r) { return (int) ($r['id'] ?? 0); }, $rows))));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $key = static function ($s) {
            return function_exists('kop_facility_pages_name_key') ? kop_facility_pages_name_key($s) : kop_normalize_name_key((string) $s);
        };

        // news id => [url => ['name' => record name, 'keys' => [name keys]]]
        $linked = [];
        try {
            $q = $pdo->query("SELECT l.news_id, f.id, f.name FROM news_facility_links l JOIN facilities_v2 f ON f.id = l.facility_id
                               WHERE l.news_id IN ($in) ORDER BY f.name");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $l['id']) : '';
                if ($url === '') continue; // no page: the name stays text
                $nid = (int) $l['news_id'];
                $linked[$nid][$url]['name'] = trim((string) $l['name']);
                $linked[$nid][$url]['keys'][$key($l['name'])] = true;
                $linked[$nid][$url]['ids'][(int) $l['id']] = true;
            }
        } catch (Throwable $e) {
            // no link table: names stay text
        }
        try {
            $prefix = function_exists('kop_v2_detect_prefix') ? kop_v2_detect_prefix($pdo) : (isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '');
            $q = $pdo->query("SELECT o.link_id, op.id, op.name FROM `{$prefix}kop_operator_links` o JOIN `{$prefix}kop_operators` op ON op.id = o.operator_id
                               WHERE o.link_kind = 'news' AND o.link_id IN ($in) ORDER BY op.name");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $url = function_exists('kop_operator_page_url') ? (string) kop_operator_page_url((int) $l['id']) : '';
                if ($url === '') continue;
                $nid = (int) $l['link_id'];
                $linked[$nid][$url]['name'] = $linked[$nid][$url]['name'] ?? trim((string) $l['name']);
                $linked[$nid][$url]['keys'][$key($l['name'])] = true;
            }
        } catch (Throwable $e) {
            // no operator links: company names stay text
        }

        // The record Facilities from News matched a mention to ("Laurel Heights").
        $matched = []; // news id => lower(mention) => facility id
        try {
            $q = $pdo->query("SELECT news_id, mention, facility_id FROM news_facility_candidates
                               WHERE news_id IN ($in) AND facility_id IS NOT NULL AND facility_id > 0");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $matched[(int) $c['news_id']][strtolower(trim((string) $c['mention']))] = (int) $c['facility_id'];
            }
        } catch (Throwable $e) {
            // no candidates table
        }

        $out = [];
        foreach ($rows as $r) {
            $nid = (int) ($r['id'] ?? 0);
            $links = $linked[$nid] ?? [];
            $shown = [];
            $list = [];
            foreach (kop_normalize_facility_mentions($r['facilities_mentioned'] ?? '[]') as $m) {
                $name = $m['name'];
                $fids = array_filter([(int) $m['facility_id'], $matched[$nid][strtolower($name)] ?? 0]);
                $keys = [];
                foreach (kop_facility_name_variants($name) as $v) $keys[$key($v)] = true;
                $opId = ($links && function_exists('kop_operator_page_id_for_name')) ? (int) kop_operator_page_id_for_name($name) : 0;
                $opUrl = $opId > 0 ? (string) kop_operator_page_url($opId) : '';
                $hit = '';
                foreach ($links as $url => $l) {
                    if (array_intersect_key($keys, $l['keys'] ?? [])
                        || array_intersect_key(array_flip($fids), $l['ids'] ?? [])
                        || ($opUrl !== '' && $opUrl === $url)) {
                        $hit = $url;
                        break;
                    }
                }
                if ($hit !== '' && isset($shown[$hit])) continue; // two spellings of one record: name it once
                if ($hit !== '') $shown[$hit] = true;
                $list[] = ['name' => $name, 'url' => $hit];
            }
            foreach ($links as $url => $l) {
                if (!isset($shown[$url])) $list[] = ['name' => $l['name'], 'url' => $url];
            }
            $out[$nid] = $list;
        }
        return $out;
    }
}
