<?php
/**
 * Ongoing story arcs: big, long-running stories that span many distinct
 * articles over months (a lawsuit's filings and reactions, a facility's
 * closure saga).
 *
 * Distinct from story_group_id (news-story-groups.php), which clusters the
 * SAME event covered by different outlets inside a 14-day window. An arc is
 * a curated narrative: created by an admin in api/manage-story-arcs.php (or
 * added from a suggestion at Review inbox > Suggested ongoing stories,
 * inc/story-arc-suggestions.php), membership assigned manually there and
 * automatically here via each arc's match_terms (newline-separated phrases;
 * an article whose title/summary/facilities contain one is attached on save).
 *
 * A "since: YYYY-MM-DD" line in match_terms bounds the arc in time: articles
 * published before that date (or undated) never join automatically, and
 * saving the arc in the tool takes the earlier ones off (kop_news_arc_prune).
 * Without it a term like "provo canyon school" pulled every article naming
 * the school since 1980 into a 2026 closure story (Claude note #5, 333
 * articles).
 *
 * Two more kinds of line (plan 4.7 #18, 2026-11-16):
 *  - "facility: 11021" joins every article linked to that facility record
 *    (news_facility_links), whatever words it uses; the suggested stories
 *    are built on it.
 *  - "not: <phrase>" keeps out an article whose title, summary or facility
 *    names contain the phrase; "not: #10464" keeps out that one article.
 *    Detach in Manage Ongoing Stories writes the second kind, so a detached
 *    article stays off when it is saved again or the archive is scanned.
 *    Saving the arc takes off the members a not line now keeps out.
 *
 * Tables: news_story_arcs + news_submissions.story_arc_id (see
 * api/update-schema.php). All helpers tolerate a database that predates the
 * migration — arc assignment is an enhancement and must never fail a save.
 */

require_once __DIR__ . '/news-mentions.php';

if (!function_exists('kop_news_arc_slug')) {

    /** URL slug for an arc title: lowercase, hyphenated, trimmed. */
    function kop_news_arc_slug(?string $title): string {
        $s = strtolower(trim((string) $title));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim(substr($s, 0, 191), '-');
    }

    /**
     * Every rule line of an arc's match_terms: 'terms' (lowercased phrases),
     * 'facilities' (record ids from "facility:" lines), 'not' (lowercased
     * phrases from "not:" lines), 'not_ids' (article ids from "not: #N") and
     * 'since' ('YYYY-MM-DD' or '').
     */
    function kop_news_arc_rules(?string $matchTerms): array {
        $out = ['terms' => [], 'facilities' => [], 'not' => [], 'not_ids' => [], 'since' => ''];
        foreach (preg_split('/\r\n|\r|\n/', (string) $matchTerms) as $line) {
            $t = strtolower(trim($line));
            if ($t === '') {
                continue;
            }
            if (preg_match('/^since:\s*(\d{4}-\d{2}-\d{2})$/', $t, $m)) {
                $out['since'] = $m[1];
            } elseif (str_starts_with($t, 'since:')) {
                continue; // a since line with no usable date bounds nothing
            } elseif (preg_match('/^facility:\s*#?(\d+)$/', $t, $m)) {
                $out['facilities'][] = (int) $m[1];
            } elseif (preg_match('/^not:\s*#(\d+)$/', $t, $m)) {
                $out['not_ids'][] = (int) $m[1];
            } elseif (preg_match('/^not:\s*(.+)$/', $t, $m)) {
                $out['not'][] = trim($m[1]);
            } else {
                $out['terms'][] = $t;
            }
        }
        return $out;
    }

    /** Parsed match terms for an arc row: lowercased phrases, empties and rule lines dropped. */
    function kop_news_arc_terms(?string $matchTerms): array {
        return kop_news_arc_rules($matchTerms)['terms'];
    }

    /** Does the arc have anything that can attach an article (a phrase or a facility line)? */
    function kop_news_arc_can_match(?string $matchTerms): bool {
        $r = kop_news_arc_rules($matchTerms);
        return $r['terms'] || $r['facilities'];
    }

    /** match_terms with "not: #<id>" added (once), so a detached article stays off. */
    function kop_news_arc_add_not_id(?string $matchTerms, int $newsId): string {
        $terms = rtrim((string) $matchTerms);
        if (in_array($newsId, kop_news_arc_rules($terms)['not_ids'], true)) {
            return $terms;
        }
        return ltrim($terms . "\nnot: #" . $newsId);
    }

    /** match_terms without its "not: #<id>" line (Undo of a detach). */
    function kop_news_arc_drop_not_id(?string $matchTerms, int $newsId): string {
        $lines = preg_split('/\r\n|\r|\n/', (string) $matchTerms);
        $keep = array_filter($lines, static fn($l) => !preg_match('/^\s*not:\s*#' . $newsId . '\s*$/i', $l));
        return implode("\n", $keep);
    }

    /** Facility record ids an article is linked to (news_facility_links). */
    function kop_news_arc_linked_facilities(PDO $pdo, int $newsId): array {
        try {
            $stmt = $pdo->prepare("SELECT facility_id FROM news_facility_links WHERE news_id = ?");
            $stmt->execute([$newsId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            return [];
        }
    }

    /** The arc's "since: YYYY-MM-DD" line as 'YYYY-MM-DD', or '' when it has none. */
    function kop_news_arc_since(?string $matchTerms): string {
        return kop_news_arc_rules($matchTerms)['since'];
    }

    /**
     * May an article published on $pubDate join the arc automatically? Always
     * without a since line; with one, only when it is dated on or after it.
     */
    function kop_news_arc_in_window(array $arc, ?string $pubDate): bool {
        $since = kop_news_arc_since($arc['match_terms'] ?? '');
        if ($since === '') {
            return true;
        }
        $day = substr(trim((string) $pubDate), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && $day >= $since;
    }

    /**
     * Take off the arc's members dated before its since line, and the members
     * a not line keeps out. Undated members stay unless a not line names
     * them (an admin attached them, or they predate the line). Returns how
     * many came off.
     */
    function kop_news_arc_prune(PDO $pdo, int $arcId): int {
        $stmt = $pdo->prepare("SELECT match_terms FROM news_story_arcs WHERE id = ?");
        $stmt->execute([$arcId]);
        $rules = kop_news_arc_rules((string) $stmt->fetchColumn());
        $off = 0;
        if ($rules['since'] !== '') {
            $detach = $pdo->prepare(
                "UPDATE news_submissions SET story_arc_id = NULL
                 WHERE story_arc_id = ? AND publication_date >= '1000-01-01' AND publication_date < ?"
            );
            $detach->execute([$arcId, $rules['since']]);
            $off += $detach->rowCount();
        }
        if ($rules['not'] || $rules['not_ids']) {
            $rows = $pdo->prepare(
                "SELECT id, article_title, alternate_title, summary, facilities_mentioned
                 FROM news_submissions WHERE story_arc_id = ?"
            );
            $rows->execute([$arcId]);
            $detach = $pdo->prepare("UPDATE news_submissions SET story_arc_id = NULL WHERE id = ? AND story_arc_id = ?");
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (kop_news_arc_excluded($rules, kop_news_arc_haystack($row), (int) $row['id'])) {
                    $detach->execute([(int) $row['id'], $arcId]);
                    $off += $detach->rowCount();
                }
            }
        }
        return $off;
    }

    /**
     * The searchable haystack for one news row: title, alternate title,
     * summary, and facility names, lowercased.
     */
    function kop_news_arc_haystack(array $row): string {
        $parts = [
            $row['article_title'] ?? '',
            $row['alternate_title'] ?? '',
            $row['summary'] ?? '',
        ];
        foreach (kop_facility_mention_names($row['facilities_mentioned'] ?? '[]') as $name) {
            $parts[] = $name;
        }
        return strtolower(implode(' | ', array_filter($parts, static fn($p) => trim((string) $p) !== '')));
    }

    /** Does a not line keep this article out? */
    function kop_news_arc_excluded(array $rules, string $haystack, int $newsId = 0): bool {
        if ($newsId > 0 && in_array($newsId, $rules['not_ids'], true)) {
            return true;
        }
        foreach ($rules['not'] as $phrase) {
            if ($phrase !== '' && str_contains($haystack, $phrase)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does this row match the arc? Returns what matched (the phrase, or
     * "facility: N") or null. $facilityIds are the records the article is
     * linked to; $newsId lets a "not: #N" line keep that one article out.
     */
    function kop_news_arc_match(array $arc, string $haystack, array $facilityIds = [], int $newsId = 0): ?string {
        $rules = kop_news_arc_rules($arc['match_terms'] ?? '');
        if (kop_news_arc_excluded($rules, $haystack, $newsId)) {
            return null;
        }
        foreach ($rules['terms'] as $term) {
            if (str_contains($haystack, $term)) {
                return $term;
            }
        }
        foreach ($rules['facilities'] as $fid) {
            if (in_array($fid, $facilityIds, true)) {
                return 'facility: ' . $fid;
            }
        }
        return null;
    }

    /**
     * Auto-attach a just-saved row to the first matching ACTIVE arc (by
     * display_order, then id). Manual curation wins: a row that already has a
     * story_arc_id is left alone. Returns the arc id, or null.
     */
    function kop_news_assign_story_arc(PDO $pdo, int $id): ?int {
        try {
            $stmt = $pdo->prepare(
                "SELECT article_title, alternate_title, summary, facilities_mentioned, story_arc_id, publication_date
                 FROM news_submissions WHERE id = ?"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !empty($row['story_arc_id'])) {
                return $row ? ($row['story_arc_id'] !== null ? (int) $row['story_arc_id'] : null) : null;
            }

            $arcs = $pdo->query(
                "SELECT id, match_terms FROM news_story_arcs
                 WHERE status = 'active' AND match_terms IS NOT NULL AND match_terms != ''
                 ORDER BY display_order ASC, id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!$arcs) {
                return null;
            }

            $haystack = kop_news_arc_haystack($row);
            $linked = kop_news_arc_linked_facilities($pdo, $id);
            foreach ($arcs as $arc) {
                if (kop_news_arc_in_window($arc, $row['publication_date'] ?? '') && kop_news_arc_match($arc, $haystack, $linked, $id) !== null) {
                    $pdo->prepare("UPDATE news_submissions SET story_arc_id = ? WHERE id = ?")
                        ->execute([(int) $arc['id'], $id]);
                    return (int) $arc['id'];
                }
            }
            return null;
        } catch (PDOException $e) {
            // Arc assignment is an enhancement; never let it fail a save
            // (e.g. the news_story_arcs migration not run yet).
            error_log('kop_news_assign_story_arc: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Attach every live, unassigned article matching the arc's terms.
     * Used by the admin tool when an arc is created or its terms change, and
     * when a suggested story is added (inc/story-arc-suggestions.php).
     * Returns the number of newly attached articles; with $ids, the attached
     * article ids are added to it.
     */
    function kop_news_arc_scan(PDO $pdo, int $arcId, ?array &$ids = null): int {
        $stmt = $pdo->prepare("SELECT id, match_terms FROM news_story_arcs WHERE id = ?");
        $stmt->execute([$arcId]);
        $arc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$arc || !kop_news_arc_can_match($arc['match_terms'] ?? '')) {
            return 0;
        }
        $facilities = kop_news_arc_rules($arc['match_terms'])['facilities'];
        $linked = [];   // news id => linked facility ids, only the arc's own facilities
        if ($facilities) {
            $ph = implode(',', array_fill(0, count($facilities), '?'));
            $links = $pdo->prepare("SELECT news_id, facility_id FROM news_facility_links WHERE facility_id IN ($ph)");
            $links->execute($facilities);
            foreach ($links->fetchAll(PDO::FETCH_NUM) as [$nid, $fid]) {
                $linked[(int) $nid][] = (int) $fid;
            }
        }

        $rows = $pdo->query(
            "SELECT id, article_title, alternate_title, summary, facilities_mentioned, publication_date
             FROM news_submissions
             WHERE story_arc_id IS NULL AND status NOT IN ('rejected', 'deleted')"
        )->fetchAll(PDO::FETCH_ASSOC);

        $attach = $pdo->prepare("UPDATE news_submissions SET story_arc_id = ? WHERE id = ?");
        $attached = 0;
        foreach ($rows as $row) {
            $nid = (int) $row['id'];
            if (kop_news_arc_in_window($arc, $row['publication_date'] ?? '')
                && kop_news_arc_match($arc, kop_news_arc_haystack($row), $linked[$nid] ?? [], $nid) !== null) {
                $attach->execute([$arcId, $nid]);
                $attached++;
                if ($ids !== null) {
                    $ids[] = $nid;
                }
            }
        }
        return $attached;
    }
}
