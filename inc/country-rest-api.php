<?php
/**
 * Country page aggregation: slug helpers, facility/news/lawsuits/legislation
 * collectors, and the kop/v1/country/{slug} REST route. Mirrors the state-page
 * pipeline in rest-api.php but matches by country fields instead of US-state
 * fields, and skips the inspection-report integration (no foreign analog).
 *
 * The country list is intentionally unbounded: any slug is accepted, humanized
 * to a canonical name (dominican-republic → "Dominican Republic"), and used to
 * match against locations_master / facilities_master / news_submissions /
 * lawsuits / legislation. Page authors create a /country-name/ page and the
 * slug drives everything.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convert "Mexico" -> "mexico", "Dominican Republic" -> "dominican-republic".
 */
function kop_country_slug($country_name) {
    $slug = strtolower(trim((string)$country_name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

/**
 * Humanize a slug into a canonical country name. We trust the page author —
 * no validation list. "dominican-republic" -> "Dominican Republic".
 *
 * @return string|null Humanized name, or null for empty/invalid input.
 */
function kop_country_slug_to_name($slug) {
    if (!is_string($slug) || $slug === '') return null;
    $slug = strtolower(trim($slug));
    if ($slug === '') return null;

    $parts = explode('-', $slug);
    $small = array('and', 'of', 'the', 'de', 'la', 'le', 'du', 'des', 'el');
    $name_parts = array();
    foreach ($parts as $i => $part) {
        if ($part === '') continue;
        if ($i > 0 && in_array($part, $small, true)) {
            $name_parts[] = $part;
        } else {
            $name_parts[] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
        }
    }
    $name = trim(implode(' ', $name_parts));
    return $name !== '' ? $name : null;
}

/**
 * Build the programs list for a country. Mirrors kop_state_collect_programs
 * but matches against locationDetails.country / address.country / free-text
 * location, instead of the state fields.
 *
 * Built from the v2 facility tables (kop_v2_collect_programs()).
 */
function kop_country_collect_programs($country_name) {
    // The facility records are the v2 tables (data model phase 5 retired facilities_master
    // and emptied locations_master's facility arrays): one join, no copies.
    return kop_v2_collect_programs($country_name, 'country');
}

/**
 * Bundle programs into the active/closed/total shape the state route returns
 * via the shared merge (no foreign inspection data exists yet, so the
 * inspection list is empty). Linked news, lawsuits and memorial entries are
 * attached by the shared step.
 */
function kop_country_collect_facilities($country_name) {
    $programs = kop_country_collect_programs($country_name);
    return kop_state_merge_programs_and_inspections($programs, array(), $country_name, array('include_country' => true));
}

/**
 * Pull news_submissions whose article_location or tags reference this country.
 * Mirrors kop_state_collect_news.
 */
function kop_country_collect_news($country_name) {
    global $wpdb;

    $like_loc = '%' . $wpdb->esc_like($country_name) . '%';
    $like_tag = '%"' . $wpdb->esc_like($country_name) . '"%';

    $sql = "SELECT id, article_title, alternate_title, author, publication_name, publication_date,
                   article_url, archive_url, article_type, article_location, summary, tags, facilities_mentioned, content_warnings
            FROM news_submissions
            WHERE status IN ('approved','published')
              AND (article_location LIKE %s OR tags LIKE %s)" . (function_exists('kop_ischools_news_exclude_sql') ? kop_ischools_news_exclude_sql() : '') . "
            ORDER BY publication_date DESC, created_at DESC
            LIMIT 200";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $like_loc, $like_tag), ARRAY_A);
    if (!is_array($rows)) return array();

    return array_map(static function ($row) {
        $row['tags'] = json_decode($row['tags'] ?? '[]', true) ?: array();
        $row['facilities_mentioned'] = kop_normalize_facility_mentions($row['facilities_mentioned'] ?? '[]');
        $row['content_warnings'] = json_decode($row['content_warnings'] ?? '[]', true) ?: array();
        $row['display_title'] = !empty($row['alternate_title']) ? $row['alternate_title'] : $row['article_title'];
        return $row;
    }, $rows);
}

function kop_country_collect_lawsuits($country_name) {
    global $wpdb;
    $table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", 'lawsuits'));
    if ($table !== 'lawsuits') return array();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM lawsuits WHERE jurisdiction = %s AND publication_status = 'published'
         ORDER BY filing_date DESC, created_at DESC LIMIT 200",
        $country_name
    ), ARRAY_A);

    if (!is_array($rows)) return array();

    $json_fields = array('plaintiffs','defendants','facilities_mentioned','staff_mentioned','organizations_mentioned','claims','source_urls','document_urls','tags');
    return array_map(static function ($row) use ($json_fields) {
        foreach ($json_fields as $f) {
            $row[$f] = json_decode($row[$f] ?? '[]', true) ?: array();
        }
        return kop_public_record_row($row);
    }, $rows);
}

function kop_country_collect_legislation($country_name) {
    global $wpdb;
    $table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", 'legislation'));
    if ($table !== 'legislation') return array();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM legislation WHERE jurisdiction = %s AND publication_status = 'published'
         ORDER BY introduced_date DESC, created_at DESC LIMIT 200",
        $country_name
    ), ARRAY_A);

    if (!is_array($rows)) return array();

    $json_fields = array('sponsors','subject_tags','facilities_affected','tags');
    return array_map(static function ($row) use ($json_fields) {
        foreach ($json_fields as $f) {
            $row[$f] = json_decode($row[$f] ?? '[]', true) ?: array();
        }
        return kop_public_record_row($row);
    }, $rows);
}

/**
 * Register the country aggregation REST route.
 */
function kop_register_country_rest_routes() {
    register_rest_route(
        'kop/v1',
        '/country/(?P<slug>[a-z0-9-]+)',
        array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => function ($request) {
                $slug = strtolower($request['slug']);
                $country_name = kop_country_slug_to_name($slug);
                if (!$country_name) {
                    return new WP_Error('unknown_country', 'Missing country slug', array('status' => 404));
                }

                $facilities = kop_country_collect_facilities($country_name);
                $news = kop_country_collect_news($country_name);
                $lawsuits = kop_country_collect_lawsuits($country_name);
                $legislation = kop_country_collect_legislation($country_name);

                return rest_ensure_response(array(
                    'country' => array(
                        'name' => $country_name,
                        'slug' => kop_country_slug($country_name),
                    ),
                    // Keep the same shape as the state route so country-page.js
                    // can share rendering code paths with state-page.js.
                    'inspections' => array(
                        'has_reports' => false,
                        'page_url'    => null,
                        'dataset_urls' => array(),
                    ),
                    'facilities' => $facilities,
                    'news' => $news,
                    'lawsuits' => $lawsuits,
                    'legislation' => $legislation,
                    'counts' => array(
                        'facilities_active' => count($facilities['active']),
                        'facilities_closed' => count($facilities['closed']),
                        'facilities_total'  => $facilities['total'],
                        'news' => count($news),
                        'lawsuits' => count($lawsuits),
                        'legislation' => count($legislation),
                    ),
                ));
            },
            'args' => array(
                'slug' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ),
            ),
        )
    );
}
add_action('rest_api_init', 'kop_register_country_rest_routes');
