<?php
/**
 * Template Name: Lawsuits
 *
 * Public directory of TTI-related lawsuits and court cases.
 */

if (!defined('ABSPATH')) { exit; }

get_header();
require_once get_stylesheet_directory() . '/api/config.php';
require_once dirname(__DIR__) . '/inc/choice-lists.php';   // KOP Tools > Dropdown Lists
require_once get_stylesheet_directory() . '/api/facility-aliases.php';  // kop_normalize_name_key, kop_collect_self_names

/**
 * Lawsuit posts kept for their longer write-up (plan 4.7 item 17d, 2026-10-25): lawsuit id => post slug. The
 * row links the post as "Our write-up"; the shorter posts redirect to their row (seeds/post-redirects.json).
 */
function kop_lawsuit_writeups() {
    return array(
        2  => 'lawsuit-fuller-vs-hyde-school-2025',
        43 => 'john-doe-mdy-v-trails-academy-et-al',
        44 => 'jane-june-doe-v-trails-et-al-summary',
    );
}

/** $urls without links to our own posts that redirect back to this page (they would only reload it). */
function kop_lawsuit_drop_self_links(array $urls) {
    $redirects = function_exists('kop_post_redirects') ? kop_post_redirects() : array();
    return array_values(array_filter($urls, static function ($url) use ($redirects) {
        $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        if (preg_replace('/^www\./', '', $host) !== 'kidsoverprofits.org') return true;
        $slug = trim((string) wp_parse_url((string) $url, PHP_URL_PATH), '/');
        return !isset($redirects[$slug]) || strpos($redirects[$slug], '/lawsuits/') !== 0;
    }));
}

$status_filter  = isset($_GET['status'])       ? sanitize_text_field($_GET['status'])       : '';
$jurisdiction   = isset($_GET['jurisdiction']) ? sanitize_text_field($_GET['jurisdiction']) : '';
$claim_filter   = isset($_GET['claim'])        ? sanitize_text_field($_GET['claim'])        : '';
$dtype_filter   = isset($_GET['defendant'])    ? sanitize_key($_GET['defendant'])           : '';
if (!function_exists('kop_lawsuit_defendant_types')) require_once get_stylesheet_directory() . '/inc/lawsuit-defendant-types.php';

try {
    $where  = ["publication_status IN ('approved','published')"];
    $params = [];
    if ($status_filter)  { $where[] = 'status = ?';       $params[] = $status_filter; }
    if ($jurisdiction)   { $where[] = 'jurisdiction = ?'; $params[] = $jurisdiction; }

    $sql = 'SELECT * FROM lawsuits WHERE ' . implode(' AND ', $where)
         . ' ORDER BY filing_date DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lawsuits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($claim_filter !== '') {
        $filtered = [];
        foreach ($lawsuits as $case) {
            $claims = json_decode((string)($case['claims'] ?? '[]'), true);
            $claims = is_array($claims) ? $claims : [];
            if (in_array($claim_filter, $claims, true)) {
                $filtered[] = $case;
            }
        }
        $lawsuits = $filtered;
    }
    if ($dtype_filter !== '') {
        $lawsuits = array_values(array_filter($lawsuits, static function ($case) use ($dtype_filter) {
            return in_array($dtype_filter, kop_lawsuit_defendant_types_clean((string) ($case['defendant_types'] ?? '')), true);
        }));
    }

    // Collect filter options from all published records for dropdowns
    $all_stmt = $pdo->prepare('SELECT jurisdiction, status, claims' . (kop_lawsuits_defendant_types_ready($pdo) ? ', defendant_types' : '')
        . " FROM lawsuits WHERE publication_status IN ('approved','published')");
    $all_stmt->execute();
    $all_rows = $all_stmt->fetchAll(PDO::FETCH_ASSOC);

    $jurisdictions = [];
    $statuses      = [];
    $all_claims    = [];
    $dtypes_used   = [];
    foreach ($all_rows as $row) {
        foreach (kop_lawsuit_defendant_types_clean((string) ($row['defendant_types'] ?? '')) as $dt) $dtypes_used[$dt] = true;
        if ($row['jurisdiction']) $jurisdictions[$row['jurisdiction']] = true;
        if ($row['status'])       $statuses[$row['status']] = true;
        $claims = json_decode($row['claims'] ?? '[]', true) ?: [];
        foreach ($claims as $c) { if ($c) $all_claims[$c] = true; }
    }
    ksort($jurisdictions);
    ksort($statuses);
    ksort($all_claims);

} catch (Throwable $e) {
    // PDOException for query failures; Error when api/config.php left $pdo
    // null because the connection itself failed.
    error_log('Lawsuits page: ' . $e->getMessage());
    $kop_db_error = true;
    $lawsuits = [];
    $jurisdictions = $statuses = $all_claims = $dtypes_used = [];
}
// Defendant types in use, in the list's own order (KOP Tools > Dropdown Lists)
$dtype_options = array_intersect_key(kop_lawsuit_defendant_types('all'), $dtypes_used);

// Facilities each case is linked to (lawsuit_facility_links -> the v2 facility records)
// and the news coverage of each case (lawsuit_news_links -> news_submissions).
// Both tables are optional; a missing one just leaves the card without links.
$facility_links = [];   // lawsuit_id => [ ['name' => unique_name, 'keys' => set of normalized names] ]
$news_links     = [];   // lawsuit_id => [ article rows ]
$lawsuit_ids    = array_map(static function ($r) { return (int)$r['id']; }, $lawsuits);
if ($lawsuit_ids) {
    $ph = implode(',', array_fill(0, count($lawsuit_ids), '?'));
    try {
        // The linked rows come from the v2 tables, by id.
        require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
        $v2_prefix = kop_v2_detect_prefix($pdo);
        $stmt = $pdo->prepare(
            "SELECT lawsuit_id, facility_id FROM lawsuit_facility_links
             WHERE lawsuit_id IN ($ph) ORDER BY lawsuit_id"
        );
        $stmt->execute($lawsuit_ids);
        $link_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $by_id = [];
        foreach (kop_v2_pdo_master_rows($pdo, $v2_prefix) as $row) {
            $by_id[(int)$row['id']] = $row;
        }
        $rows = [];
        foreach ($link_rows as $link) {
            $row = $by_id[(int)$link['facility_id']] ?? null;
            if (!$row) continue;
            $rows[] = [
                'lawsuit_id' => $link['lawsuit_id'],
                'facility_id' => (int)$link['facility_id'],
                'unique_name' => $row['unique_name'],
                'json_data' => $row['json_data'],
            ];
        }
        usort($rows, static function ($a, $b) {
            return (int)$a['lawsuit_id'] === (int)$b['lawsuit_id']
                ? strcasecmp($a['unique_name'], $b['unique_name'])
                : (int)$a['lawsuit_id'] - (int)$b['lawsuit_id'];
        });
        foreach ($rows as $r) {
            $decoded = json_decode((string)$r['json_data'], true);
            $keys = [kop_normalize_name_key((string)$r['unique_name']) => true];
            foreach (array_merge(kop_collect_self_names($decoded), kop_collect_match_aliases($decoded)) as $alias) {
                $k = kop_normalize_name_key($alias);
                if ($k !== '') $keys[$k] = true;
            }
            $facility_links[(int)$r['lawsuit_id']][] = ['name' => $r['unique_name'], 'keys' => $keys, 'id' => (int)$r['facility_id']];
        }
    } catch (Throwable $e) {
        $facility_links = [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT ln.lawsuit_id, n.id, n.article_title, n.alternate_title,
                    n.publication_name, n.publication_date, n.article_url
             FROM lawsuit_news_links ln
             JOIN news_submissions n ON n.id = ln.news_id
             WHERE ln.lawsuit_id IN ($ph)
               AND ln.link_type <> 'excluded'
               AND n.status IN ('approved','published')
               AND n.article_url IS NOT NULL AND n.article_url <> ''
             ORDER BY n.publication_date DESC, n.id DESC"
        );
        $stmt->execute($lawsuit_ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $news_links[(int)$r['lawsuit_id']][] = $r;
        }
    } catch (Throwable $e) {
        $news_links = [];
    }
}

// Facility "profile" = the generated facility page when the record has one
// (inc/facility-pages.php), otherwise the directory's location tab filtered
// to that facility. Never the parent company tab: it lists operators and chains only.
$index_url = function_exists('kop_location_index_url') ? kop_location_index_url() : home_url('/tti-program-index/?view=location');
$facility_profile_url = static function (string $name, int $facility_id = 0) use ($index_url): string {
    if ($facility_id > 0 && function_exists('kop_facility_page_url')) {
        $page_url = kop_facility_page_url($facility_id);
        if ($page_url !== '') return $page_url;
    }
    return add_query_arg('search', rawurlencode($name), $index_url);
};

// Where a mention no linked row pairs with goes: the one facility page its name
// (and the case's state) pins, else the operator page of that name
// (kop_facility_link_resolve_placed()).
$mention_pairs = [];
foreach ($lawsuits as $case) {
    foreach (json_decode($case['facilities_mentioned'] ?? '[]', true) ?: [] as $m) {
        if (is_string($m)) $mention_pairs[] = [$m, (string) ($case['jurisdiction'] ?? '')];
    }
}
$mention_urls = function_exists('kop_facility_link_resolve_placed') ? kop_facility_link_resolve_placed($mention_pairs) : [];

/**
 * Pair a case's mentioned facility names with its linked facility rows.
 * A mention links when one of its spellings (kop_facility_name_variants) matches
 * the linked row's unique_name or an alias; a lone mention pairs with a lone
 * link regardless; any other mention links through $mention_urls.
 * Linked rows nothing paired with are appended so every profile is reachable.
 *
 * @return array<int, array{label:string, url:?string}>
 */
$facility_tags_for = static function (array $mentions, array $linked, string $place = '') use ($facility_profile_url, $mention_urls): array {
    $tags = [];
    $used = [];
    foreach ($mentions as $mention) {
        $match = null;
        foreach (kop_facility_name_variants((string)$mention) as $variant) {
            $key = kop_normalize_name_key($variant);
            foreach ($linked as $i => $lf) {
                if (!isset($used[$i]) && $key !== '' && isset($lf['keys'][$key])) { $match = $i; break 2; }
            }
        }
        if ($match === null && count($mentions) === 1 && count($linked) === 1 && !isset($used[0])) {
            $match = 0;
        }
        if ($match !== null) {
            $used[$match] = true;
            $tags[] = ['label' => $mention, 'url' => $facility_profile_url($linked[$match]['name'], (int)($linked[$match]['id'] ?? 0))];
        } else {
            $tags[] = ['label' => $mention, 'url' => $mention_urls[trim((string) $mention) . '|' . trim($place)] ?? null];
        }
    }
    foreach ($linked as $i => $lf) {
        if (!isset($used[$i])) {
            $tags[] = ['label' => $lf['name'], 'url' => $facility_profile_url($lf['name'], (int)($lf['id'] ?? 0))];
        }
    }
    return $tags;
};
?>

<div class="kop-records-page kop-lawsuits-page">
    <div class="kop-records-header">
        <h1>Lawsuits &amp; Legal Cases</h1>
        <?php kop_db_unavailable_notice(!empty($kop_db_error)); ?>
        <p>Court cases involving Troubled Teen Industry facilities, operators, and staff.</p>
        <details class="kop-records-methods" id="lawsuits-methods">
            <summary>How this list is made</summary>
            <ul>
                <li><strong>Where cases come from:</strong> court records, news coverage of the programs KOP tracks, and cases people send in through the <a href="<?php echo esc_url(home_url('/submit-lawsuit')); ?>">Submit a Lawsuit</a> form. An editor checks each case before it appears here.</li>
                <li><strong>Sources:</strong> each case gives its court and docket number and links to the court record or the filings themselves where KOP has them. Court papers KOP holds are kept in its document library. News articles are listed as coverage, not as the record of the case.</li>
                <li><strong>How current it is:</strong> open cases are checked against their court docket about once a day (federal cases on CourtListener, others through a docket link where the case has one). A change waits for an editor before it shows here, so a status can trail the court by a few days.</li>
                <li><strong>Brought against:</strong> the kinds of defendant the court record names (a facility, an operator or parent company, an educational consultant, a marketing or referral company). It is set by an editor from the parties list; a case without it has not been sorted yet.</li>
                <li><strong>What it is not:</strong> a complete list of every case. A complaint's claims are allegations until a court rules on them, and a settlement is not an admission of wrongdoing.</li>
                <li><strong>The data:</strong> every published case, with its parties, claims, outcome and links, downloads from <a href="<?php echo esc_url(home_url('/open-data/')); ?>">Open Data</a> (cite a monthly edition).</li>
            </ul>
        </details>
        <p class="kop-records-cta">
            Know of a case we're missing?
            <a class="kop-card-link" href="<?php echo esc_url(home_url('/submit-lawsuit')); ?>">Submit a lawsuit for review &rarr;</a>
        </p>
    </div>

    <div class="kop-records-filters" id="lawsuits-filters" data-kop-bug-feature="lawsuits/filters" data-kop-bug-label="Lawsuits Filters">
        <select class="kop-filter-select" id="filter-status" data-filter="status">
            <option value="">All case statuses</option>
            <?php foreach ($statuses as $s => $_): ?>
                <option value="<?php echo esc_attr($s); ?>" <?php selected($status_filter, $s); ?>>
                    <?php echo esc_html(kop_lawsuit_status_label($s)); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="kop-filter-select" id="filter-jurisdiction" data-filter="jurisdiction">
            <option value="">All jurisdictions</option>
            <?php foreach ($jurisdictions as $j => $_): ?>
                <option value="<?php echo esc_attr($j); ?>" <?php selected($jurisdiction, $j); ?>>
                    <?php echo esc_html($j); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="kop-filter-select" id="filter-claim" data-filter="claim">
            <option value="">All claim types</option>
            <?php foreach ($all_claims as $c => $_): ?>
                <option value="<?php echo esc_attr($c); ?>" <?php selected($claim_filter, $c); ?>>
                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $c))); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($dtype_options): ?>
        <select class="kop-filter-select" id="filter-defendant" data-filter="defendant">
            <option value="">All defendant types</option>
            <?php foreach ($dtype_options as $dt => $dt_label): ?>
                <option value="<?php echo esc_attr($dt); ?>" <?php selected($dtype_filter, $dt); ?>><?php echo esc_html($dt_label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="button" class="kop-filter-clear" id="clear-filters">Clear filters</button>
        <span class="kop-filter-count" id="filter-count"><?php echo count($lawsuits); ?> cases</span>
    </div>

    <div class="kop-records-grid" id="lawsuits-grid" data-kop-bug-feature="lawsuits/table" data-kop-bug-label="Lawsuits Table">
        <?php if (empty($lawsuits)): ?>
            <div class="kop-records-empty">No lawsuits found. Check back soon.</div>
        <?php else: ?>
        <?php foreach ($lawsuits as $case):
            $plaintiffs   = json_decode($case['plaintiffs']   ?? '[]', true) ?: [];
            $defendants   = json_decode($case['defendants']   ?? '[]', true) ?: [];
            $facilities   = json_decode($case['facilities_mentioned'] ?? '[]', true) ?: [];
            $claims       = json_decode($case['claims']       ?? '[]', true) ?: [];
            $source_urls  = json_decode($case['source_urls']  ?? '[]', true) ?: [];
            $writeup_slug = kop_lawsuit_writeups()[(int) $case['id']] ?? '';
            $writeup_url  = $writeup_slug !== '' ? home_url('/' . $writeup_slug . '/') : '';
            $source_urls  = array_values(array_filter(kop_lawsuit_drop_self_links($source_urls), static function ($url) use ($writeup_slug) {
                return $writeup_slug === '' || trim((string) wp_parse_url((string) $url, PHP_URL_PATH), '/') !== $writeup_slug;
            }));
            $doc_urls     = json_decode($case['document_urls'] ?? '[]', true) ?: [];
            $tags         = json_decode($case['tags']         ?? '[]', true) ?: [];
            $filing_date  = $case['filing_date'] ? date('M j, Y', strtotime($case['filing_date'])) : '';
            $status_slug  = $case['status'] ?: 'unknown';
            $status_label = kop_lawsuit_status_label($status_slug);   // KOP Tools > Dropdown Lists
            $claims_json  = htmlspecialchars(json_encode($claims), ENT_QUOTES);
            $dtypes       = kop_lawsuit_defendant_types_clean((string) ($case['defendant_types'] ?? ''));
            $lawsuit_id   = (int)$case['id'];
            $facility_tags = $facility_tags_for($facilities, $facility_links[$lawsuit_id] ?? [], (string) ($case['jurisdiction'] ?? ''));
            $coverage      = $news_links[$lawsuit_id] ?? [];
            // A case's own FileBird folder (and its subfolders) lists every filing,
            // minus the ones already linked as "Court doc N" above it. Only a
            // folder named like a caption ("Doe v Hyde", "... lawsuits") counts:
            // many cases point at their facility's folder of handbooks and
            // inspection reports, which are not case documents.
            $folder_docs = [];
            $case_folder_name = '';
            if (!empty($case['filebird_folder_id'])) {
                global $wpdb;
                $case_folder_name = html_entity_decode((string)$wpdb->get_var($wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}fbv WHERE id = %d", (int)$case['filebird_folder_id']
                )), ENT_QUOTES);
            }
            if (preg_match('/\bvs?\.?\s|lawsuit/i', $case_folder_name) && function_exists('kop_get_attachments_in_folder_ids')) {
                $linked = array_flip($doc_urls);
                foreach (kop_get_attachments_in_folder_ids(kop_get_descendant_ids_for_roots([(int)$case['filebird_folder_id']])) as $att) {
                    $att_url = wp_get_attachment_url($att->ID);
                    if ($att_url && !isset($linked[$att_url])) {
                        $folder_docs[] = ['title' => $att->post_title, 'url' => $att_url];
                    }
                }
                usort($folder_docs, static function ($a, $b) { return strnatcasecmp($a['title'], $b['title']); });
            }
        ?>
        <div class="kop-record-card" id="lawsuit-<?php echo (int) $case['id']; ?>"<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('rec:lawsuit:' . (int) $case['id'], (string) $case['case_name']) : ''; ?>
             data-status="<?php echo esc_attr($status_slug); ?>"
             data-jurisdiction="<?php echo esc_attr($case['jurisdiction'] ?? ''); ?>"
             data-claims="<?php echo $claims_json; ?>"
             data-defendant-types="<?php echo esc_attr(implode(' ', $dtypes)); ?>">

            <div class="kop-card-header">
                <h2 class="kop-card-title"><?php echo esc_html($case['case_name']); ?></h2>
                <span class="kop-badge kop-badge-<?php echo esc_attr(kop_lawsuit_status_badge($status_slug)); ?>"><?php echo esc_html($status_label); ?></span>
            </div>

            <div class="kop-card-meta">
                <?php if ($case['jurisdiction']): ?>
                    <span class="kop-badge kop-badge-jurisdiction"><?php echo esc_html($case['jurisdiction']); ?></span>
                <?php endif; ?>
                <?php if ($case['court']): ?>
                    <span><?php echo esc_html($case['court']); ?></span>
                <?php endif; ?>
                <?php if ($case['case_number']): ?>
                    <span><?php echo esc_html($case['case_number']); ?></span>
                <?php endif; ?>
                <?php if ($filing_date): ?>
                    <span>Filed <?php echo esc_html($filing_date); ?></span>
                <?php endif; ?>
                <?php if ($case['settlement_amount']): ?>
                    <span>Settlement: <strong><?php echo esc_html($case['settlement_amount']); ?></strong></span>
                <?php endif; ?>
            </div>

            <?php if ($plaintiffs || $defendants || $dtypes): ?>
            <div class="kop-card-parties">
                <?php if ($plaintiffs): ?>
                    <div class="kop-party-row">
                        <span class="kop-party-label">Plaintiff<?php echo count($plaintiffs) > 1 ? 's' : ''; ?>:</span>
                        <?php echo esc_html(implode(', ', array_slice($plaintiffs, 0, 3))); ?>
                        <?php if (count($plaintiffs) > 3): ?><em> +<?php echo count($plaintiffs) - 3; ?> more</em><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($defendants): ?>
                    <div class="kop-party-row">
                        <span class="kop-party-label">Defendant<?php echo count($defendants) > 1 ? 's' : ''; ?>:</span>
                        <?php echo esc_html(implode(', ', array_slice($defendants, 0, 3))); ?>
                        <?php if (count($defendants) > 3): ?><em> +<?php echo count($defendants) - 3; ?> more</em><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($dtypes): ?>
                    <div class="kop-party-row kop-party-types">
                        <span class="kop-party-label">Brought against:</span>
                        <?php echo esc_html(implode(', ', array_map('kop_lawsuit_defendant_type_label', $dtypes))); ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($claims || $facility_tags): ?>
            <div class="kop-card-tags">
                <?php foreach (array_slice($claims, 0, 5) as $claim): ?>
                    <span class="kop-tag"><?php echo esc_html(ucfirst(str_replace('_', ' ', $claim))); ?></span>
                <?php endforeach; ?>
                <?php foreach (array_slice($facility_tags, 0, 5) as $tag): ?>
                    <?php if ($tag['url']): ?>
                        <a class="kop-tag kop-tag-facility" href="<?php echo esc_url($tag['url']); ?>" title="Open this program's page"><?php echo esc_html($tag['label']); ?></a>
                    <?php else: ?>
                        <span class="kop-tag kop-tag-facility"><?php echo esc_html($tag['label']); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (count($facility_tags) > 5): ?><em class="kop-tag-more">+<?php echo count($facility_tags) - 5; ?> more</em><?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($case['summary']): ?>
            <button type="button" class="kop-card-summary-toggle" aria-expanded="false">
                <span class="toggle-arrow">▸</span> Show summary
            </button>
            <div class="kop-card-summary" hidden>
                <?php echo nl2br(esc_html($case['summary'])); ?>
                <?php if ($case['outcome']): ?>
                    <p><strong>Outcome:</strong> <?php echo esc_html($case['outcome']); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($coverage): ?>
            <div class="kop-card-coverage">
                <span class="kop-party-label">News coverage:</span>
                <ul class="kop-coverage-list">
                    <?php foreach ($coverage as $i => $art):
                        $art_title = $art['alternate_title'] ?: $art['article_title'];
                        $art_meta  = array_filter([
                            $art['publication_name'],
                            $art['publication_date'] ? date('M j, Y', strtotime($art['publication_date'])) : '',
                        ]);
                    ?>
                    <li class="kop-coverage-item"<?php echo $i >= 3 ? ' hidden data-coverage-extra' : ''; ?>>
                        <a href="<?php echo esc_url($art['article_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($art_title); ?></a>
                        <?php if ($art_meta): ?><span class="kop-coverage-meta"><?php echo esc_html(implode(' - ', $art_meta)); ?></span><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($coverage) > 3): ?>
                    <button type="button" class="kop-coverage-more" aria-expanded="false">Show <?php echo count($coverage) - 3; ?> more article<?php echo count($coverage) - 3 === 1 ? '' : 's'; ?></button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($writeup_url || $source_urls || $doc_urls): ?>
            <div class="kop-card-links">
                <?php if ($writeup_url): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($writeup_url); ?>">Our write-up &rarr;</a>
                <?php endif; ?>
                <?php foreach (array_slice($source_urls, 0, 2) as $url): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Source &rarr;</a>
                <?php endforeach; ?>
                <?php foreach (array_values($doc_urls) as $doc_i => $url): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Court doc<?php echo count($doc_urls) > 1 ? ' ' . ($doc_i + 1) : ''; ?> &rarr;</a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($folder_docs): ?>
            <details class="kop-card-docs">
                <summary><?php echo esc_html(count($folder_docs) . ' more case document' . (count($folder_docs) === 1 ? '' : 's')); ?></summary>
                <ul class="kop-coverage-list">
                    <?php foreach ($folder_docs as $doc): ?>
                    <li class="kop-coverage-item"><a href="<?php echo esc_url($doc['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($doc['title']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cards = Array.from(document.querySelectorAll('.kop-record-card'));
    const countEl = document.getElementById('filter-count');

    function getFilters() {
        return {
            status:       document.getElementById('filter-status').value,
            jurisdiction: document.getElementById('filter-jurisdiction').value,
            claim:        document.getElementById('filter-claim').value,
            defendant:    (document.getElementById('filter-defendant') || {}).value || '',
        };
    }

    function applyFilters() {
        const f = getFilters();
        let visible = 0;
        cards.forEach(card => {
            const statusMatch = !f.status || card.dataset.status === f.status;
            const jurisMatch  = !f.jurisdiction || card.dataset.jurisdiction === f.jurisdiction;
            let claimMatch = true;
            if (f.claim) {
                try {
                    const claims = JSON.parse(card.dataset.claims || '[]');
                    claimMatch = claims.includes(f.claim);
                } catch(_) { claimMatch = false; }
            }
            const typeMatch = !f.defendant || (card.dataset.defendantTypes || '').split(' ').includes(f.defendant);
            const show = statusMatch && jurisMatch && claimMatch && typeMatch;
            card.hidden = !show;
            if (show) visible++;
        });
        countEl.textContent = visible + ' case' + (visible !== 1 ? 's' : '');
    }

    document.querySelectorAll('.kop-filter-select').forEach(sel => sel.addEventListener('change', applyFilters));

    document.getElementById('clear-filters').addEventListener('click', function() {
        document.querySelectorAll('.kop-filter-select').forEach(sel => sel.value = '');
        applyFilters();
    });

    document.querySelectorAll('.kop-card-summary-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const summary = this.nextElementSibling;
            const open = summary.hasAttribute('hidden');
            summary.toggleAttribute('hidden', !open);
            this.setAttribute('aria-expanded', open);
            this.querySelector('.toggle-arrow').textContent = open ? '▾' : '▸';
            this.childNodes[1].textContent = open ? ' Hide summary' : ' Show summary';
        });
    });

    document.querySelectorAll('.kop-coverage-more').forEach(btn => {
        const label = btn.textContent;
        btn.addEventListener('click', function() {
            const expand = this.getAttribute('aria-expanded') !== 'true';
            this.closest('.kop-card-coverage').querySelectorAll('[data-coverage-extra]')
                .forEach(li => { li.hidden = !expand; });
            this.setAttribute('aria-expanded', expand);
            this.textContent = expand ? 'Show fewer articles' : label;
        });
    });
});
</script>

<?php get_footer(); ?>
